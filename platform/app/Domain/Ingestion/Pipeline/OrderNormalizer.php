<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Pipeline;

use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Ingestion\Support\CurrencyRegistry;
use App\Domain\Ingestion\Support\EmailNormalizer;
use App\Domain\Ingestion\Support\ExchangeRateProvider;
use App\Domain\Ingestion\Support\MoneyParser;
use App\Domain\Ingestion\Support\TimestampParser;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Convierte una orden cruda en una orden normalizada, o explica por que no
 * pudo.
 *
 * Cada bloque de este metodo corresponde a un tipo de suciedad del
 * enunciado, y ninguno corta el proceso: se revisa todo y se acumulan los
 * problemas, para que el retailer se entere de una vez de todo lo que tiene
 * que arreglar.
 *
 * La regla que atraviesa todas las decisiones: descartar el registro es la
 * ultima opcion, y solo cuando el dato faltante hace que el registro no
 * signifique nada. Un email roto no cuesta una venta; un monto ilegible si,
 * porque contaminaria todos los agregados que lo toquen.
 */
final class OrderNormalizer
{
    public function __construct(
        private readonly ExchangeRateProvider $rates,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload, Tenant $tenant, RejectBag $bag): ?NormalizedOrder
    {
        // ── Pertenencia al tenant ─────────────────────────────────
        // Un lote puede traer registros que no son del tenant que los envia.
        // Se detecta aca; si esta validacion fallara, la politica WITH CHECK
        // de PostgreSQL rechazaria igual el INSERT.
        $declaredTenant = $this->stringOrNull($payload['tenant_id'] ?? null);

        if ($declaredTenant !== null && $declaredTenant !== $tenant->id && $declaredTenant !== $tenant->slug) {
            $bag->add(
                RejectReason::ForeignTenant,
                "El registro declara pertenecer al tenant [{$declaredTenant}].",
                'tenant_id',
            );

            return null;
        }

        // ── Identificador de origen ───────────────────────────────
        $externalId = $this->stringOrNull($payload['order_id'] ?? $payload['external_id'] ?? null);

        if ($externalId === null) {
            $bag->add(
                RejectReason::MissingIdentifier,
                'La orden no trae identificador de origen; sin el no hay forma de deduplicar ni actualizar.',
                'order_id',
            );

            return null;
        }

        $customer = $this->normalizeCustomer($payload['customer'] ?? null, $bag);

        // ── Moneda ────────────────────────────────────────────────
        $currencyRaw = $this->stringOrNull($payload['currency'] ?? null);

        if ($currencyRaw === null) {
            $bag->add(RejectReason::MissingField, 'Falta la moneda de la orden.', 'currency');
        } elseif (! CurrencyRegistry::isKnown($currencyRaw)) {
            $bag->add(
                RejectReason::UnknownCurrency,
                "Moneda [{$currencyRaw}] no reconocida. Convertirla con una tasa inventada sería peor que descartarla.",
                'currency',
            );
        }

        $currency = $currencyRaw !== null && CurrencyRegistry::isKnown($currencyRaw)
            ? CurrencyRegistry::normalize($currencyRaw)
            : null;

        // ── Monto ─────────────────────────────────────────────────
        $totalMinor = $this->normalizeTotal($payload, $currency, $bag);

        // ── Estado ────────────────────────────────────────────────
        $status = $this->normalizeStatus($payload, $bag);

        // ── Fechas ────────────────────────────────────────────────
        $placedAt = $this->normalizeTimestamp(
            $payload['placed_at'] ?? $payload['created_at'] ?? null,
            $tenant->timezone,
            'placed_at',
            $bag,
        );

        // Si el origen no dice cuando genero este estado, se asume que fue al
        // momento de la venta. Es conservador: hace que una actualizacion sin
        // marca temporal nunca pise a una que si la tiene. Si la marca vino
        // pero es ilegible, la suposicion es la misma pero queda anotada: es
        // la unica forma de que el reporte del lote no le eche la culpa al
        // orden de llegada.
        $sourceUpdatedAt = $this->normalizeTimestamp(
            $payload['updated_at'] ?? $payload['source_updated_at'] ?? null,
            $tenant->timezone,
            'updated_at',
            $bag,
            required: false,
        ) ?? $placedAt;

        $items = $this->normalizeItems($payload['items'] ?? [], $currency, $bag);

        if ($bag->discardsRecord() || $currency === null || $totalMinor === null
            || $status === null || $placedAt === null || $customer === null) {
            return null;
        }

        // ── Conversion a moneda de reporte ────────────────────────
        // Con la tasa del dia de la orden, no la de hoy, y guardandola.
        $reportingCurrency = CurrencyRegistry::normalize($tenant->reporting_currency);
        $fxRate = $this->rates->rate($currency, $reportingCurrency, $placedAt);
        $reportingMinor = $this->rates->convertMinor($totalMinor, $currency, $reportingCurrency, $placedAt);

        return new NormalizedOrder(
            externalId: $externalId,
            customer: $customer,
            status: $status,
            currency: $currency,
            totalAmountMinor: $totalMinor,
            reportingAmountMinor: $reportingMinor,
            fxRate: $fxRate,
            placedAt: $placedAt,
            // El dia calendario en la zona del TENANT. Es la columna por la
            // que agrupa el dashboard, y precalcularla evita aplicar una
            // funcion sobre millones de filas en cada consulta.
            placedOn: $placedAt->setTimezone($tenant->timezone)->format('Y-m-d'),
            sourceUpdatedAt: $sourceUpdatedAt ?? $placedAt,
            items: $items,
        );
    }

    private function normalizeCustomer(mixed $raw, RejectBag $bag): ?NormalizedCustomer
    {
        if (! is_array($raw)) {
            $bag->add(RejectReason::MissingField, 'La orden no trae datos de comprador.', 'customer');

            return null;
        }

        $externalId = $this->stringOrNull($raw['customer_id'] ?? $raw['external_id'] ?? null);

        if ($externalId === null) {
            $bag->add(
                RejectReason::MissingIdentifier,
                'El comprador no trae identificador de origen.',
                'customer.customer_id',
            );

            return null;
        }

        $email = EmailNormalizer::normalize($raw['email'] ?? null);

        // Rechazo BLANDO: se anota el problema y el cliente sobrevive.
        if ($email['status'] === EmailNormalizer::STATUS_INVALID) {
            $bag->add(
                RejectReason::EmailInvalid,
                "El email [{$email['original']}] no es válido; se conserva el comprador con el email marcado.",
                'customer.email',
            );
        }

        $country = $this->stringOrNull($raw['country'] ?? null);

        return new NormalizedCustomer(
            externalId: $externalId,
            name: $this->stringOrNull($raw['name'] ?? null),
            email: $email['original'],
            emailNormalized: $email['normalized'],
            emailStatus: $email['status'],
            phone: $this->stringOrNull($raw['phone'] ?? null),
            country: $country !== null ? strtoupper(substr($country, 0, 2)) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeTotal(array $payload, ?string $currency, RejectBag $bag): ?int
    {
        $key = array_key_exists('total', $payload) ? 'total' : 'total_amount';
        $raw = $payload[$key] ?? null;

        if ($this->isBlank($raw)) {
            // Ausente y vacio no son lo mismo. Un retailer que no manda el
            // campo puede simplemente no tenerlo; uno que manda "" tiene un
            // hueco en su propio sistema. Distinguirlos es lo que permite
            // decirle cual de los dos problemas tiene.
            $bag->add(
                array_key_exists($key, $payload) ? RejectReason::EmptyField : RejectReason::MissingField,
                array_key_exists($key, $payload)
                    ? 'El total de la orden llegó vacío.'
                    : 'Falta el total de la orden.',
                'total',
            );

            return null;
        }

        if ($currency === null) {
            // Sin moneda no se puede escalar a unidades minimas. Ya se anoto
            // el problema de la moneda; no se duplica el rechazo.
            return null;
        }

        $minor = MoneyParser::toMinorUnits($raw, $currency);

        if ($minor === null) {
            $bag->add(
                RejectReason::InvalidType,
                'El total no se puede interpretar como número.',
                'total',
                $raw,
            );

            return null;
        }

        if ($minor < 0) {
            $bag->add(
                RejectReason::InvalidAmount,
                'El total es negativo; contaminaría todos los agregados que lo toquen.',
                'total',
                $raw,
            );

            return null;
        }

        return $minor;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeStatus(array $payload, RejectBag $bag): ?OrderStatus
    {
        $raw = $payload['status'] ?? null;
        $value = $this->stringOrNull($raw);

        if ($value === null) {
            $present = array_key_exists('status', $payload);

            $bag->add(
                $present ? RejectReason::EmptyField : RejectReason::MissingField,
                $present ? 'El estado de la orden llegó vacío.' : 'Falta el estado de la orden.',
                'status',
            );

            return null;
        }

        $status = OrderStatus::fromLoose($value);

        if ($status === null) {
            $bag->add(
                RejectReason::UnknownStatus,
                "Estado [{$value}] no se puede mapear a ninguno conocido.",
                'status',
                $raw,
            );
        }

        return $status;
    }

    private function normalizeTimestamp(
        mixed $raw,
        string $timezone,
        string $field,
        RejectBag $bag,
        bool $required = true,
    ): ?CarbonImmutable {
        if ($raw === null || $raw === '') {
            if ($required) {
                $bag->add(RejectReason::MissingField, "Falta la fecha [{$field}].", $field);
            }

            return null;
        }

        $parsed = TimestampParser::parse($raw, $timezone);

        if ($parsed === null) {
            // Una fecha opcional ilegible no descarta la orden, pero tampoco
            // puede pasar en silencio. Al caer sobre `placed_at`, la
            // actualizacion termina descartada mas adelante como
            // `stale_update`, y ese diagnostico culpa al orden de llegada
            // cuando el problema real es que la fecha no se entendio.
            $bag->add(
                $required ? RejectReason::MalformedDate : RejectReason::UnreadableUpdateTimestamp,
                $required
                    ? "La fecha [{$field}] no se pudo interpretar en ningún formato conocido."
                    : "La fecha [{$field}] no se pudo interpretar; se asume el momento de la venta.",
                $field,
                $raw,
            );
        }

        return $parsed;
    }

    /**
     * @return list<NormalizedItem>
     */
    private function normalizeItems(mixed $raw, ?string $currency, RejectBag $bag): array
    {
        if (! is_array($raw) || $raw === []) {
            // Una orden sin renglones es rara pero no imposible (ajustes,
            // notas de credito). No se descarta: se registra el importe
            // total, que es lo que alimenta el revenue.
            return [];
        }

        $items = [];

        foreach ($raw as $index => $item) {
            if (! is_array($item) || $currency === null) {
                continue;
            }

            $productId = $this->stringOrNull($item['product_id'] ?? $item['sku'] ?? null);

            if ($productId === null) {
                $bag->add(
                    RejectReason::MissingIdentifier,
                    "El renglón #{$index} no identifica ningún producto.",
                    "items.{$index}.product_id",
                );

                continue;
            }

            $quantity = $this->normalizeQuantity($item, $index, $bag);

            $unitRaw = $item['unit_price'] ?? $item['price'] ?? null;
            $totalRaw = $item['total'] ?? null;

            $unitMinor = $unitRaw === null
                ? 0
                : $this->normalizeLineAmount($unitRaw, $currency, "items.{$index}.unit_price", $bag);

            // El total del renglon se deriva solo cuando no vino. Si vino y
            // no se entiende, derivarlo igual seria tapar el problema con un
            // numero inventado que despues nadie puede distinguir de uno real.
            $totalMinor = $totalRaw !== null
                ? $this->normalizeLineAmount($totalRaw, $currency, "items.{$index}.total", $bag)
                : ($unitMinor !== null && $quantity !== null ? $unitMinor * $quantity : null);

            if ($quantity === null || $unitMinor === null || $totalMinor === null) {
                // El motivo tipado ya quedo en la bolsa; la orden entera se
                // descarta al salir del normalizador.
                continue;
            }

            $items[] = new NormalizedItem(
                productExternalId: $productId,
                sku: $this->stringOrNull($item['sku'] ?? null),
                name: $this->stringOrNull($item['name'] ?? null) ?? $productId,
                category: $this->stringOrNull($item['category'] ?? null),
                quantity: $quantity,
                unitAmountMinor: $unitMinor,
                totalAmountMinor: $totalMinor,
                currency: $currency,
                externalId: $this->stringOrNull($item['item_id'] ?? null),
            );
        }

        return $items;
    }

    /**
     * Cantidad de un renglon, o null si llego y no se puede leer.
     *
     * Ausente y presente-pero-ilegible no son el mismo problema, igual que
     * con el total de la orden. Un renglon sin cantidad es una unidad: es la
     * convencion de cualquier documento de venta. Una cantidad que llego y no
     * se entiende, en cambio, no se puede reemplazar por uno: los hechos por
     * producto dejarian de cuadrar contra los de venta y nadie se enteraria.
     *
     * @param  array<string, mixed>  $item
     */
    private function normalizeQuantity(array $item, int|string $index, RejectBag $bag): ?int
    {
        $raw = $item['quantity'] ?? null;

        if ($raw === null) {
            return 1;
        }

        $quantity = filter_var($raw, FILTER_VALIDATE_INT);

        if ($quantity === false || $quantity < 1) {
            $bag->add(
                RejectReason::InvalidQuantity,
                "La cantidad del renglón #{$index} no es un entero positivo.",
                "items.{$index}.quantity",
                $raw,
            );

            return null;
        }

        return $quantity;
    }

    /**
     * Importe de un renglon, o null si no se puede interpretar.
     *
     * Misma politica que el total de la orden, y por el mismo motivo: un
     * importe inventado contamina todos los agregados que lo toquen. La
     * diferencia es a que tabla derivada llega el dano: `daily_product_facts`
     * en vez de `daily_sales_facts`.
     */
    private function normalizeLineAmount(mixed $raw, string $currency, string $field, RejectBag $bag): ?int
    {
        $minor = MoneyParser::toMinorUnits($raw, $currency);

        if ($minor === null) {
            $bag->add(
                RejectReason::InvalidType,
                "El importe del renglón [{$field}] no se puede interpretar como número.",
                $field,
                $raw,
            );

            return null;
        }

        if ($minor < 0) {
            $bag->add(
                RejectReason::InvalidAmount,
                "El importe del renglón [{$field}] es negativo.",
                $field,
                $raw,
            );

            return null;
        }

        return $minor;
    }

    /**
     * Un valor que no aporta informacion: nulo, cadena vacia, solo espacios.
     */
    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Colapsa cadena vacia y solo-espacios a nulo.
     *
     * Es la unica forma de que "campo vacio" y "campo ausente" se traten
     * igual, que es lo que corresponde: un retailer que manda "" no esta
     * diciendo algo distinto de uno que no manda el campo.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
