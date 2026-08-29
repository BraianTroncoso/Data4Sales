<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Ingestion\Models\IngestionReject;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Genera la tabla de suciedad que pide el enunciado.
 *
 * La tabla NO se escribe a mano en el README: se genera desde el codigo.
 * La diferencia importa mas de lo que parece. Una tabla escrita a mano
 * documenta lo que alguien creia que hacia el sistema el dia que la
 * escribio, y envejece en silencio. Una tabla generada no puede quedar
 * desactualizada sin que el codigo cambie primero.
 *
 * Con --tenant agrega lo que REALMENTE paso con el dataset cargado, que es
 * la diferencia entre documentar una politica y demostrarla.
 */
class DirtTableCommand extends Command
{
    protected $signature = 'd4s:dirt-table
        {--tenant= : Slug del tenant, para incluir los conteos observados}
        {--markdown : Emitir en formato Markdown para pegar en el README}';

    protected $description = 'Genera la tabla que mapea cada tipo de suciedad con lo que hace el sistema';

    /**
     * Casos que no son rechazos de un registro, sino comportamientos del
     * sistema. Van a mano porque no viven en el enum: no hay una fila de
     * rechazo que los represente, y meterlos ahi seria forzar el modelo.
     *
     * @var list<array{string, string, string}>
     */
    private const NON_REJECT_CASES = [
        [
            'Registro duplicado exacto',
            'El mismo registro llega dos veces, idéntico byte por byte.',
            'Índice único sobre (tenant_id, hash del contenido canonicalizado). Lo resuelve el motor de forma atómica: dos workers en paralelo no pueden insertarlo dos veces. Se cuenta como duplicado en el lote y no se reprocesa.',
        ],
        [
            'Duplicado parcial',
            'Mismo identificador de origen, contenido distinto.',
            'No es un duplicado sino una actualización. Upsert sobre (tenant_id, external_id) con guarda de versión: gana el evento con `source_updated_at` más nuevo, sin importar el orden de llegada.',
        ],
        [
            'Dato que llega tarde',
            'Una orden de hace dos meses aparece en el envío de hoy.',
            'Se acepta con normalidad. Se guardan por separado `placed_at` (cuándo ocurrió) y `received_at` (cuándo llegó), y el día afectado se marca en `aggregate_dirty_days` para que un trabajo recalcule sólo ese día. El retraso es medible en el campo `freshness` de las métricas.',
        ],
        [
            'Dos o más monedas',
            'El mismo tenant vende en distintas monedas.',
            'Se conserva el monto en su moneda original y se agrega el equivalente en la moneda de reporte, con la tasa del día de la orden congelada en la fila. Nunca se convierte al vuelo.',
        ],
        [
            'Fechas en distintos formatos y zonas',
            'ISO con offset, epoch, formato latino, o sin zona horaria.',
            'Se prueban varios formatos. Si la fecha no trae zona, se interpreta en la del TENANT y no en la del servidor: una venta a las 22:30 en Buenos Aires se cuenta ese día, no el siguiente.',
        ],
        [
            'Emails repetidos entre clientes distintos',
            'Dos compradores comparten casilla.',
            'Se acepta sin observaciones. El email es dato de contacto, no identidad: el índice sobre `email_normalized` NO es único, a propósito. Familias que comparten casilla y cuentas corporativas son casos reales.',
        ],
        [
            'Envío que se interrumpe a mitad de camino',
            'Llegan menos registros de los que el emisor anunció.',
            'El lote queda en estado `partial` con la cuenta exacta de lo recibido. Como la zona cruda es append-only y la normalización es idempotente, reenviar el lote completo es seguro: lo ya ingerido se cuenta como duplicado y no entra dos veces.',
        ],
    ];

    public function handle(TenantContext $context): int
    {
        $observed = $this->observedCounts($context);

        $rows = [];

        foreach (self::NON_REJECT_CASES as [$dirt, $detail, $policy]) {
            $rows[] = [$dirt, $detail, $policy, '—'];
        }

        foreach (RejectReason::cases() as $reason) {
            $rows[] = [
                $reason->describesDirt(),
                '`'.$reason->value.'`',
                $reason->describesPolicy(),
                $observed === null ? '—' : (string) ($observed[$reason->value] ?? 0),
            ];
        }

        $this->option('markdown')
            ? $this->renderMarkdown($rows, $observed !== null)
            : $this->renderTable($rows);

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>|null
     */
    private function observedCounts(TenantContext $context): ?array
    {
        $slug = (string) $this->option('tenant');

        if ($slug === '') {
            return null;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->warn("El tenant [{$slug}] no existe; se omiten los conteos observados.");

            return null;
        }

        return $context->run($tenant, fn () => IngestionReject::query()
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason')
            ->map(fn ($value) => (int) $value)
            ->all());
    }

    /**
     * @param  list<array{string, string, string, string}>  $rows
     */
    private function renderTable(array $rows): void
    {
        $this->table(
            ['Suciedad', 'Detalle', 'Qué hace el sistema', 'Observado'],
            array_map(fn (array $row) => [
                $row[0],
                mb_strimwidth($row[1], 0, 40, '…'),
                mb_strimwidth($row[2], 0, 80, '…'),
                $row[3],
            ], $rows),
        );
    }

    /**
     * @param  list<array{string, string, string, string}>  $rows
     */
    private function renderMarkdown(array $rows, bool $withCounts): void
    {
        $header = $withCounts
            ? '| Suciedad | Detalle | Qué hace el sistema | Observado |'
            : '| Suciedad | Detalle | Qué hace el sistema |';

        $divider = $withCounts ? '|---|---|---|---:|' : '|---|---|---|';

        $this->line($header);
        $this->line($divider);

        foreach ($rows as $row) {
            $cells = $withCounts ? $row : array_slice($row, 0, 3);

            $this->line('| '.implode(' | ', array_map(
                static fn (string $cell) => str_replace('|', '\\|', $cell),
                $cells,
            )).' |');
        }
    }
}
