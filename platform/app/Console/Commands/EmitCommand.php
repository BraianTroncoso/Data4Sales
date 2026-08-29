<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Emitter\DatasetGenerator;
use App\Domain\Emitter\DatasetProfile;
use App\Domain\Emitter\IngestApiClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emisor de datos: genera el dataset y lo empuja contra la API.
 *
 * Corre en su propio contenedor, SIN credenciales de base de datos. Aunque
 * el codigo viva en el mismo repositorio, ese contenedor no puede escribir
 * en PostgreSQL ni aunque quisiera. Es la diferencia entre respetar una
 * regla y no poder romperla.
 *
 * Ejemplos:
 *
 *   # Carga histórica de un retailer que recién se integra
 *   php artisan d4s:emit --tenant=luna --mode=historical --orders=3000
 *
 *   # Flujo del día a día: ventas nuevas y actualizaciones de estado
 *   php artisan d4s:emit --tenant=luna --mode=daily --orders=200
 *
 *   # Envío que se corta a la mitad
 *   php artisan d4s:emit --tenant=luna --orders=500 --interrupt
 */
class EmitCommand extends Command
{
    protected $signature = 'd4s:emit
        {--tenant= : Slug del tenant (luna, andes, mega)}
        {--mode=historical : historical (carga inicial) o daily (flujo continuo)}
        {--orders=1000 : Cuántas órdenes generar}
        {--batch=500 : Registros por envío}
        {--seed= : Semilla del generador; por defecto se deriva del tenant}
        {--offset=0 : Desde qué número de orden arrancar; para repartir una carga entre procesos}
        {--api-key= : Credencial; por defecto sale del entorno}
        {--base-url= : URL de la API; por defecto D4S_API_BASE_URL}
        {--interrupt : Simular un envío que se corta a la mitad}
        {--no-wait : No esperar a que la ingesta termine de procesar}';

    protected $description = 'Genera un dataset reproducible y lo envía a la API de ingesta';

    /** Cuánto se espera, como mucho, a que la ingesta termine de procesar. */
    private const SETTLE_TIMEOUT_SECONDS = 600;

    public function handle(Http $http): int
    {
        $slug = (string) $this->option('tenant');

        if ($slug === '') {
            $this->error('Falta --tenant. Valores del seed: luna, andes, mega.');

            return self::INVALID;
        }

        $apiKey = $this->resolveApiKey($slug);

        if ($apiKey === null) {
            $this->error(
                "No hay credencial para [{$slug}]. Pasá --api-key, o definí "
                .'D4S_API_KEY_'.mb_strtoupper($slug).' en el entorno (ver config/d4s.php).'
            );

            return self::INVALID;
        }

        $baseUrl = (string) ($this->option('base-url') ?: config('d4s.emitter.base_url'));
        $mode = (string) $this->option('mode');
        $total = max(1, (int) $this->option('orders'));
        $batchSize = max(1, (int) $this->option('batch'));

        // La semilla se deriva del tenant para que cada uno tenga un dataset
        // distinto pero cada uno sea reproducible. Volver a correr el mismo
        // comando produce exactamente los mismos registros.
        $seed = (int) ($this->option('seed') ?: crc32($slug));

        $profile = DatasetProfile::forTenant($slug);
        $generator = new DatasetGenerator($profile, $seed);
        $client = new IngestApiClient($http, $baseUrl, $apiKey);

        $this->components->info("Emitiendo hacia {$baseUrl} — tenant [{$slug}], modo [{$mode}], semilla {$seed}");

        /*
         * El desplazamiento es lo que hace posible repartir una carga grande
         * entre varios procesos. La semilla NO alcanza: cambia el contenido de
         * las órdenes, no su identificador, así que dos corridas sin
         * desplazamiento generan el mismo rango y la segunda entra entera como
         * duplicado. Correcto desde el punto de vista de la idempotencia, e
         * inútil desde el punto de vista del volumen.
         */
        $offset = max(0, (int) $this->option('offset'));

        $records = $mode === 'daily'
            ? $generator->dailyOrders($total, existingOrders: $offset + $total * 2)
            : $generator->historicalOrders($total, startAt: $offset + 1);

        return $this->stream($client, $records, $batchSize, $mode, $slug, $seed, $offset);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $records
     */
    private function stream(
        IngestApiClient $client,
        iterable $records,
        int $batchSize,
        string $mode,
        string $slug,
        int $seed,
        int $offset,
    ): int {
        $source = $mode === 'daily' ? 'daily_stream' : 'historical_backfill';

        $buffer = [];
        $batchNumber = 0;
        $sent = 0;
        $batchIds = [];
        $interruptAt = $this->option('interrupt') ? 1 : null;

        $bar = $this->output->createProgressBar();
        $bar->start();

        try {
            foreach ($records as $record) {
                $buffer[] = $record;

                if (count($buffer) < $batchSize) {
                    continue;
                }

                $batchNumber++;

                $result = $this->flush($client, $buffer, $source, $slug, $seed, $offset, $batchNumber, $interruptAt === $batchNumber);

                $sent += $result['sent'];
                $batchIds[] = $result['batch_id'];

                $bar->advance(count($buffer));
                $buffer = [];
            }

            if ($buffer !== []) {
                $batchNumber++;
                $result = $this->flush($client, $buffer, $source, $slug, $seed, $offset, $batchNumber, false);

                $sent += $result['sent'];
                $batchIds[] = $result['batch_id'];

                $bar->advance(count($buffer));
            }
        } catch (Throwable $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error('El envío falló: '.$e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Lotes enviados', (string) $batchNumber);
        $this->components->twoColumnDetail('Registros enviados', (string) $sent);

        if ($this->option('no-wait')) {
            $this->newLine();
            $this->line('  La ingesta es asincrónica: consultá el resultado en');
            $this->line('  <fg=cyan>GET /api/v1/ingest/batches/{batch_id}</>');

            return self::SUCCESS;
        }

        $this->reportFinalCounts($client, array_filter($batchIds));

        return self::SUCCESS;
    }

    /**
     * Espera a que la ingesta termine y reporta lo que realmente pasó.
     *
     * Sin este paso el resumen mostraría las cuentas del MOMENTO DE
     * RECEPCIÓN, cuando todavía no se procesó nada: cero aceptados y cero
     * rechazados, que es literalmente cierto y completamente inútil. Con
     * ingesta asincrónica, "entregado" y "aceptado" son dos cosas distintas,
     * y el emisor tiene que mirar la segunda.
     *
     * Esperar y leer son DOS PASOS separados, y esa separación es la
     * corrección de un error real: la primera versión leía los contadores en
     * el mismo sondeo que detectaba el fin, así que un lote que terminaba
     * entre dos sondeos se reportaba con los contadores de la vuelta
     * anterior. El resultado eran números plausibles pero más bajos que los
     * de la base — la peor clase de error de reporte, porque no parece un
     * error.
     *
     * @param  list<string>  $batchIds
     */
    private function reportFinalCounts(IngestApiClient $client, array $batchIds): void
    {
        if ($batchIds === []) {
            return;
        }

        $this->components->info('Esperando a que la ingesta procese los lotes…');

        $pendientes = $this->waitUntilSettled($client, $batchIds);

        if ($pendientes !== []) {
            $this->newLine();
            $this->warn('  '.count($pendientes).' lote(s) siguen procesándose. Los números de abajo los excluyen.');
        }

        $totales = ['accepted' => 0, 'rejected' => 0, 'duplicate' => 0];
        $parciales = 0;

        // Recién ahora se leen los contadores, una vez, con todos los lotes
        // ya en estado final.
        foreach (array_diff($batchIds, $pendientes) as $batchId) {
            $data = $client->batchStatus($batchId)['data'] ?? [];
            $counts = $data['counts'] ?? [];

            foreach (array_keys($totales) as $k) {
                $totales[$k] += (int) ($counts[$k] ?? 0);
            }

            if (($data['status'] ?? '') === 'partial') {
                $parciales++;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Aceptados</>', (string) $totales['accepted']);
        $this->components->twoColumnDetail('<fg=yellow>Rechazados</>', (string) $totales['rejected']);
        $this->components->twoColumnDetail('<fg=blue>Duplicados exactos</>', (string) $totales['duplicate']);

        if ($parciales > 0) {
            $this->components->twoColumnDetail('<fg=red>Lotes incompletos</>', (string) $parciales);
        }

        $this->newLine();
        $this->line('  Los rechazos, agrupados por motivo, se consultan en:');
        $this->line("  <fg=cyan>GET /api/v1/ingest/batches/{$batchIds[0]}/rejects</>");
        $this->newLine();
        $this->line('  O la tabla completa de suciedad, con conteos reales:');
        $this->line('  <fg=cyan>php artisan d4s:dirt-table --tenant='.($this->option('tenant') ?: 'luna').'</>');
    }

    /**
     * Espera a que todos los lotes lleguen a un estado final.
     *
     * @param  list<string>  $batchIds
     * @return list<string> los que siguieron pendientes al vencer el plazo
     */
    private function waitUntilSettled(IngestApiClient $client, array $batchIds): array
    {
        $terminales = ['completed', 'partial', 'failed'];
        $deadline = time() + self::SETTLE_TIMEOUT_SECONDS;
        $pendientes = $batchIds;

        while ($pendientes !== [] && time() < $deadline) {
            $pendientes = array_values(array_filter(
                $pendientes,
                fn (string $id) => ! in_array(
                    $client->batchStatus($id)['data']['status'] ?? '',
                    $terminales,
                    strict: true,
                ),
            ));

            if ($pendientes !== []) {
                usleep(500_000);
            }
        }

        return $pendientes;
    }

    /**
     * Envía un lote y devuelve sus cuentas.
     *
     * La clave de idempotencia es determinista: mismo tenant, misma semilla,
     * mismo desplazamiento, mismo número de lote. Volver a correr el emisor
     * con los mismos parámetros reenvía las MISMAS claves, así que el servidor
     * reconoce los lotes y no duplica nada. Es lo que hace que este comando se
     * pueda ejecutar dos veces sin miedo.
     *
     * El desplazamiento entra en la clave por una razón concreta: sin él, dos
     * procesos que reparten una carga grande comparten tenant, semilla y
     * numeración de lote, así que el servidor tomaría los lotes del segundo
     * como reintentos del primero y descartaría la mitad de los datos sin
     * error visible.
     *
     * @param  list<array<string, mixed>>  $buffer
     * @return array{sent: int, batch_id: string|null}
     */
    private function flush(
        IngestApiClient $client,
        array $buffer,
        string $source,
        string $slug,
        int $seed,
        int $offset,
        int $batchNumber,
        bool $interrupt,
    ): array {
        $idempotencyKey = Str::of("{$slug}-{$seed}-{$offset}-{$source}-{$batchNumber}")->slug()->toString();

        $toSend = $buffer;
        $expected = null;

        // Envío interrumpido: se declaran N registros y se mandan menos. Sin
        // el conteo declarado, el servidor no tendría forma de distinguir
        // "mandaron pocos" de "se cortó a la mitad".
        if ($interrupt) {
            $expected = count($buffer);
            $toSend = array_slice($buffer, 0, max(1, intdiv(count($buffer), 2)));

            $this->newLine();
            $this->warn("  Lote {$batchNumber}: simulando corte — se anuncian {$expected} registros y se envían ".count($toSend).'.');
        }

        $response = $client->sendBatch('orders', $source, $toSend, $idempotencyKey, $expected);

        return [
            'sent' => count($toSend),
            'batch_id' => $response['data']['batch_id'] ?? null,
        ];
    }

    /**
     * La credencial viene por opción o por configuración, nunca de la base: el
     * emisor no tiene acceso a PostgreSQL, y ese es justamente el punto.
     *
     * Se lee con `config()` y no con `env()`. La diferencia importa: `env()`
     * devuelve null cuando la configuración está cacheada, así que un emisor
     * que la leyera directo dejaría de encontrar su credencial apenas alguien
     * corre `config:cache`. Hoy funcionaba sólo porque el contenedor tiene el
     * cacheo apagado — una coincidencia, no un diseño.
     */
    private function resolveApiKey(string $slug): ?string
    {
        $explicit = (string) $this->option('api-key');

        if ($explicit !== '') {
            return $explicit;
        }

        $key = config('d4s.emitter.api_keys.'.mb_strtolower($slug))
            ?? config('d4s.emitter.default_api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
