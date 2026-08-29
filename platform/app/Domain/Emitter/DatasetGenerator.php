<?php

declare(strict_types=1);

namespace App\Domain\Emitter;

use Carbon\CarbonImmutable;
use Generator;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generador reproducible del dataset de ventas.
 *
 * Reproducible de verdad: con la misma semilla produce byte por byte el
 * mismo dataset. Se usa Mt19937 con semilla explicita en vez de las
 * funciones globales de PHP porque estas ultimas comparten estado con todo
 * lo demas del proceso, y basta que otra libreria consuma un numero al azar
 * para que el "dataset reproducible" deje de serlo sin ningun aviso.
 *
 * Devuelve un Generator y no un array. Con millones de ordenes, materializar
 * el dataset entero en memoria antes de enviarlo es exactamente el problema
 * que se quiere demostrar que no tenemos.
 *
 * LA SUCIEDAD ES PARTE DEL PRODUCTO. Este generador ensucia a proposito, con
 * proporciones controladas, cada uno de los casos que pide el enunciado. La
 * lista completa y que hace el sistema con cada uno esta en el README, y esa
 * tabla se genera desde el codigo (`php artisan d4s:dirt-table`).
 */
final class DatasetGenerator
{
    /** Proporcion de cada tipo de suciedad sobre el total de registros. */
    private const DIRT_RATES = [
        'exact_duplicate' => 0.02,
        'partial_duplicate' => 0.02,
        'missing_field' => 0.015,
        'empty_field' => 0.01,
        'wrong_type' => 0.01,
        'bad_date_format' => 0.04,
        'invalid_email' => 0.02,
        'shared_email' => 0.03,
        'foreign_tenant' => 0.005,
        'unknown_currency' => 0.005,
        'unknown_status' => 0.005,
        'negative_total' => 0.005,
    ];

    /** @var list<string> */
    private const CATEGORIES = [
        'indumentaria', 'calzado', 'accesorios', 'hogar',
        'deportes', 'electronica', 'libros', 'juguetes',
    ];

    /** @var list<string> */
    private const FIRST_NAMES = [
        'María', 'Juan', 'Camila', 'Mateo', 'Sofía', 'Lucas', 'Valentina',
        'Benjamín', 'Martina', 'Thiago', 'Isabella', 'Joaquín', 'Emma', 'Bruno',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'González', 'Rodríguez', 'Fernández', 'López', 'Martínez', 'Pérez',
        'Silva', 'Romero', 'Sosa', 'Álvarez', 'Torres', 'Ruiz',
    ];

    private readonly Randomizer $random;

    public function __construct(
        private readonly DatasetProfile $profile,
        int $seed,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /**
     * Carga inicial: la historia completa de un retailer que recien se
     * integra. Fechas repartidas sobre todo el rango, mayormente altas.
     *
     * `$startAt` desplaza el numero de orden. Sin el, dos corridas del mismo
     * comando producen el MISMO rango de identificadores y la segunda entra
     * entera como duplicado: la ingesta la rechaza bien, pero el volumen no
     * crece. Es lo unico que permite repartir una carga grande entre varios
     * procesos, porque la semilla no cambia el identificador — cambia el
     * contenido.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function historicalOrders(int $count, int $startAt = 1): Generator
    {
        $start = $this->profile->from;
        $days = max(1, (int) $start->diffInDays($this->profile->to));
        $emitted = 0;

        for ($i = $startAt; $i < $startAt + $count; $i++) {
            $dayOffset = $this->random->getInt(0, $days);
            $placedAt = $start->addDays($dayOffset)
                ->addHours($this->random->getInt(8, 22))
                ->addMinutes($this->random->getInt(0, 59));

            // Las claves se emiten explicitamente y siempre crecientes. Con
            // `yield from` cada generador interno reinicia sus claves desde
            // cero, y cualquier consumidor que use iterator_to_array sin
            // desactivar la preservacion de claves perderia casi todo el
            // dataset sin ningun error. El emisor usa foreach y no se veria
            // afectado, pero es una trampa que no vale la pena dejar puesta.
            foreach ($this->buildWithDuplicates($i, $placedAt, historical: true) as $record) {
                yield $emitted++ => $record;
            }
        }
    }

    /**
     * Flujo del dia a dia: ventas nuevas y actualizaciones de estado de
     * ordenes que ya existen.
     *
     * Una parte deliberada son ordenes VIEJAS que aparecen recien hoy. Es el
     * caso del enunciado: la orden de hace dos meses que llega en el envio
     * de hoy, y que obliga a recalcular agregados del pasado.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function dailyOrders(int $count, int $existingOrders): Generator
    {
        $emitted = 0;

        for ($i = 1; $i <= $count; $i++) {
            $roll = $this->random->getFloat(0, 1);

            // 35%: actualizacion de estado de una orden que ya existe.
            if ($roll < 0.35 && $existingOrders > 0) {
                yield $emitted++ => $this->statusUpdate($this->random->getInt(1, $existingOrders));

                continue;
            }

            // 15%: dato que llega tarde, con hasta dos meses de atraso.
            $placedAt = $roll < 0.50
                ? $this->profile->to->subDays($this->random->getInt(20, 60))
                : $this->profile->to->subHours($this->random->getInt(0, 23));

            foreach ($this->buildWithDuplicates($existingOrders + $i, $placedAt, historical: false) as $record) {
                yield $emitted++ => $record;
            }
        }
    }

    /**
     * Produce una orden y, con cierta probabilidad, sus variantes sucias.
     *
     * @return list<array<string, mixed>>
     */
    private function buildWithDuplicates(int $sequence, CarbonImmutable $placedAt, bool $historical): array
    {
        $order = $this->buildOrder($sequence, $placedAt, $historical);
        $records = [$order];

        // Duplicado EXACTO: el mismo registro otra vez, sin ninguna
        // diferencia. Lo detecta el índice único sobre el hash de contenido.
        if ($this->chance('exact_duplicate')) {
            $records[] = $order;
        }

        // Duplicado PARCIAL: mismo identificador de origen, contenido
        // distinto. Es una actualización legítima, y la resuelve la guarda
        // de versión: gana la que tenga la marca temporal más nueva.
        if ($this->chance('partial_duplicate')) {
            $variant = $order;
            $variant['status'] = 'shipped';
            $variant['updated_at'] = $placedAt->addDays(2)->toIso8601String();

            $records[] = $variant;
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrder(int $sequence, CarbonImmutable $placedAt, bool $historical): array
    {
        $customerIndex = $this->random->getInt(1, $this->profile->customers);
        $currency = $this->pickCurrency();
        $items = $this->buildItems($currency);

        $total = array_sum(array_map(
            static fn (array $item) => (float) $item['unit_price'] * $item['quantity'],
            $items,
        ));

        $order = [
            'order_id' => sprintf('%s-ORD-%07d', strtoupper($this->profile->slug), $sequence),
            'status' => $this->pickStatus($placedAt, $historical),
            'currency' => $currency,
            'total' => $this->formatAmount($total),
            'placed_at' => $this->formatDate($placedAt),
            'updated_at' => $this->formatDate($placedAt->addHours($this->random->getInt(0, 72))),
            'customer' => $this->buildCustomer($customerIndex),
            'items' => $items,
        ];

        return $this->dirty($order);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomer(int $index): array
    {
        $first = self::FIRST_NAMES[$this->random->getInt(0, count(self::FIRST_NAMES) - 1)];
        $last = self::LAST_NAMES[$this->random->getInt(0, count(self::LAST_NAMES) - 1)];

        // Los acentos se transcriben, como hace cualquier sistema real al
        // derivar un email de un nombre. Dejarlos produciría direcciones que
        // el validador rechaza por no ser ASCII, y el conteo de "emails
        // inválidos" pasaría a estar dominado por ese artefacto en vez de
        // por la suciedad que este generador inyecta a propósito.
        $email = sprintf(
            '%s.%s%d@example.test',
            self::asciify($first),
            self::asciify($last),
            $index,
        );

        // Email COMPARTIDO entre compradores distintos: familias, cuentas
        // corporativas, la caja de una tienda. El sistema tiene que
        // aceptarlo, porque el email no es identidad.
        if ($this->chance('shared_email')) {
            $email = 'familia.compartida@example.test';
        }

        // Email INVÁLIDO: no descarta al comprador, sólo se marca.
        if ($this->chance('invalid_email')) {
            $email = $this->random->getInt(0, 1) === 0
                ? "{$first} {$last} sin arroba"
                : "{$first}@localhost";
        }

        return [
            'customer_id' => sprintf('%s-CUS-%06d', strtoupper($this->profile->slug), $index),
            'name' => "{$first} {$last}",
            'email' => $email,
            'country' => $this->profile->country,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildItems(string $currency): array
    {
        $count = $this->random->getInt(1, 4);
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $productIndex = $this->random->getInt(1, $this->profile->products);
            $category = self::CATEGORIES[$productIndex % count(self::CATEGORIES)];

            $items[] = [
                'product_id' => sprintf('%s-PRD-%05d', strtoupper($this->profile->slug), $productIndex),
                'sku' => sprintf('SKU-%05d', $productIndex),
                'name' => ucfirst($category).' '.$productIndex,
                'category' => $category,
                'quantity' => $this->random->getInt(1, 3),
                'unit_price' => $this->formatAmount($this->random->getInt(500, 80000) / 100),
            ];
        }

        return $items;
    }

    /**
     * Ensucia el registro segun las proporciones declaradas.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function dirty(array $order): array
    {
        if ($this->chance('missing_field')) {
            // Incluye el identificador de origen: sin él no hay forma de
            // deduplicar ni de actualizar después, y el sistema lo trata
            // como un caso aparte del resto de los campos faltantes.
            unset($order[['total', 'status', 'order_id'][$this->random->getInt(0, 2)]]);
        }

        if ($this->chance('empty_field')) {
            $campo = $this->random->getInt(0, 1) === 0 ? 'status' : 'total';

            if (isset($order[$campo])) {
                $order[$campo] = '   ';
            }
        }

        if ($this->chance('wrong_type')) {
            $order['total'] = 'no disponible';
        }

        // Sólo si el campo sigue estando: una regla anterior pudo haberlo
        // eliminado. Las suciedades se aplican en cadena sobre el mismo
        // registro, así que cada una tiene que tolerar lo que hicieron las
        // anteriores.
        if (isset($order['total']) && $this->chance('negative_total')) {
            $order['total'] = '-'.$order['total'];
        }

        if (isset($order['currency']) && $this->chance('unknown_currency')) {
            $order['currency'] = 'XYZ';
        }

        if (isset($order['status']) && $this->chance('unknown_status')) {
            $order['status'] = 'en_veremos';
        }

        // Registro que NO pertenece al tenant que lo está enviando.
        if ($this->chance('foreign_tenant')) {
            $order['tenant_id'] = '00000000-0000-4000-8000-000000000000';
        }

        return $order;
    }

    /**
     * Fechas en formatos y zonas horarias distintas, como llegan en la
     * realidad cuando el retailer integra tres sistemas que no se hablan.
     */
    private function formatDate(CarbonImmutable $date): string|int
    {
        if (! $this->chance('bad_date_format')) {
            return $date->setTimezone($this->profile->timezone)->toIso8601String();
        }

        return match ($this->random->getInt(0, 4)) {
            0 => $date->format('Y-m-d H:i:s'),          // sin zona: se interpreta en la del tenant
            1 => $date->format('d/m/Y'),                 // formato latino, sin hora
            2 => $date->getTimestamp(),                  // epoch en segundos
            3 => $date->utc()->toIso8601ZuluString(),    // UTC explícito
            default => 'fecha desconocida',              // ilegible: se rechaza
        };
    }

    /**
     * Montos en las dos convenciones decimales del mundo.
     */
    private function formatAmount(float $amount): string
    {
        return match ($this->random->getInt(0, 3)) {
            0 => number_format($amount, 2, ',', '.'),   // 1.234,56
            1 => number_format($amount, 2, '.', ','),   // 1,234.56
            2 => (string) round($amount, 2),
            default => number_format($amount, 2, '.', ''),
        };
    }

    private function pickCurrency(): string
    {
        // La moneda principal domina, pero siempre hay una minoría en otra:
        // ventas al exterior, un marketplace, una tienda de frontera.
        return $this->random->getFloat(0, 1) < 0.85
            ? $this->profile->currency
            : $this->profile->secondaryCurrency;
    }

    /**
     * Estado coherente con cuánto tiempo pasó desde la venta.
     *
     * Una orden de ayer no puede estar entregada; una de hace tres meses casi
     * seguro lo está. Sin esto el dataset tendría estados al azar y las
     * métricas de recompra y devolución no significarían nada.
     */
    private function pickStatus(CarbonImmutable $placedAt, bool $historical): string
    {
        $ageInDays = (int) $placedAt->diffInDays($this->profile->to);
        $roll = $this->random->getFloat(0, 1);

        if ($ageInDays < 2) {
            return $roll < 0.5 ? 'created' : 'paid';
        }

        if ($ageInDays < 7) {
            return match (true) {
                $roll < 0.15 => 'paid',
                $roll < 0.75 => 'shipped',
                $roll < 0.95 => 'delivered',
                default => 'cancelled',
            };
        }

        return match (true) {
            $roll < 0.80 => 'delivered',
            $roll < 0.90 => 'returned',
            $roll < 0.97 => 'cancelled',
            default => 'shipped',
        };
    }

    /**
     * Actualización de estado de una orden que ya existe.
     *
     * Es el caso que ejercita la guarda de versión: llega sólo el cambio,
     * con una marca temporal nueva.
     *
     * @return array<string, mixed>
     */
    private function statusUpdate(int $sequence): array
    {
        $placedAt = $this->profile->to->subDays($this->random->getInt(1, 80));

        return [
            'order_id' => sprintf('%s-ORD-%07d', strtoupper($this->profile->slug), $sequence),
            'status' => ['shipped', 'delivered', 'returned', 'cancelled'][$this->random->getInt(0, 3)],
            'currency' => $this->profile->currency,
            'total' => $this->formatAmount($this->random->getInt(500, 80000) / 100),
            'placed_at' => $this->formatDate($placedAt),
            'updated_at' => $this->profile->to->toIso8601String(),
            'customer' => $this->buildCustomer($this->random->getInt(1, $this->profile->customers)),
            'items' => $this->buildItems($this->profile->currency),
        ];
    }

    /**
     * Transcribe acentos y ene a ASCII.
     *
     * Se hace a mano y no con iconv porque iconv depende del locale del
     * sistema, y un dataset "reproducible" que cambia segun donde corre no
     * es reproducible.
     */
    private static function asciify(string $value): string
    {
        return mb_strtolower(strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]));
    }

    private function chance(string $kind): bool
    {
        return $this->random->getFloat(0, 1) < self::DIRT_RATES[$kind];
    }

    /**
     * @return array<string, float>
     */
    public static function dirtRates(): array
    {
        return self::DIRT_RATES;
    }
}
