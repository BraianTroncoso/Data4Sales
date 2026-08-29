<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

/**
 * Catalogo de suciedad conocida.
 *
 * Esta enum es deliberadamente el centro del manejo de datos sucios. Cada
 * caso lleva su descripcion y la politica que aplica el sistema, de modo que
 * la tabla que pide el enunciado en el README se GENERA desde aca
 * (`php artisan d4s:dirt-table`) en vez de escribirse a mano.
 *
 * La diferencia importa: una tabla escrita a mano documenta lo que alguien
 * creia que hacia el sistema el dia que la escribio. Una tabla generada
 * documenta lo que el sistema hace hoy, y no puede quedar desactualizada
 * sin que el codigo cambie.
 *
 * Notar que no todo rechazo descarta el registro. `EMAIL_INVALID` anota el
 * problema y conserva el cliente: perder un comprador entero porque su mail
 * tiene un espacio de mas seria peor que quedarse sin ese mail.
 */
enum RejectReason: string
{
    // ── Estructura ────────────────────────────────────────────────
    case MissingField = 'missing_field';
    case EmptyField = 'empty_field';
    case InvalidType = 'invalid_type';

    // ── Identidad y pertenencia ───────────────────────────────────
    case ForeignTenant = 'foreign_tenant';
    case MissingIdentifier = 'missing_identifier';

    // ── Valores de negocio ────────────────────────────────────────
    case InvalidAmount = 'invalid_amount';
    case InvalidQuantity = 'invalid_quantity';
    case UnknownCurrency = 'unknown_currency';
    case UnknownStatus = 'unknown_status';
    case MalformedDate = 'malformed_date';
    case EmailInvalid = 'email_invalid';

    // ── Orden temporal ────────────────────────────────────────────
    case StaleUpdate = 'stale_update';
    case UnreadableUpdateTimestamp = 'unreadable_update_timestamp';

    // ── Alcance del pipeline ──────────────────────────────────────
    // No es suciedad del retailer: es una limitacion declarada de esta
    // version. Vale un motivo tipado igual, porque el emisor necesita saber
    // que su envio quedo guardado pero no aplicado.
    case UnsupportedEntity = 'unsupported_entity';

    /**
     * Si el registro sobrevive al rechazo o se descarta entero.
     *
     * Los rechazos "blandos" anotan el problema en un campo y conservan el
     * registro. Los "duros" lo descartan, porque sin ese dato el registro no
     * significa nada.
     */
    public function discardsRecord(): bool
    {
        return match ($this) {
            self::EmailInvalid, self::StaleUpdate, self::UnreadableUpdateTimestamp => false,
            default => true,
        };
    }

    /** Que llego mal. */
    public function describesDirt(): string
    {
        return match ($this) {
            self::MissingField => 'Falta un campo obligatorio',
            self::EmptyField => 'Campo obligatorio presente pero vacío',
            self::InvalidType => 'Campo con tipo o formato inesperado',
            self::ForeignTenant => 'El registro declara pertenecer a otro tenant',
            self::MissingIdentifier => 'El registro no trae identificador de origen',
            self::InvalidAmount => 'Monto negativo o no numérico',
            self::InvalidQuantity => 'Cantidad de renglón que no es un entero positivo',
            self::UnknownCurrency => 'Moneda que no es un código ISO-4217 conocido',
            self::UnknownStatus => 'Estado de orden que no se puede mapear',
            self::MalformedDate => 'Fecha en un formato que no se puede interpretar',
            self::EmailInvalid => 'Email que no pasa validación',
            self::StaleUpdate => 'Actualización más vieja que el estado ya guardado',
            self::UnreadableUpdateTimestamp => 'Marca temporal de actualización ilegible',
            self::UnsupportedEntity => 'Entidad que esta versión todavía no normaliza',
        };
    }

    /** Que hace el sistema al respecto. */
    public function describesPolicy(): string
    {
        return match ($this) {
            self::MissingField => 'Se descarta el registro y se anota el campo faltante. El resto del lote sigue.',
            self::EmptyField => 'Cadena vacía y sólo-espacios se normalizan a nulo. Si el campo era obligatorio, se descarta el registro.',
            self::InvalidType => 'Se intenta coerción tolerante (por ejemplo "1.234,56" a entero). Si no se puede, se descarta el registro.',
            self::ForeignTenant => 'Se descarta el registro. Además, la política WITH CHECK de PostgreSQL rechazaría el INSERT aunque esta validación fallara.',
            self::MissingIdentifier => 'Se descarta: sin identificador de origen no hay forma de deduplicar ni de actualizar después.',
            self::InvalidAmount => 'Se descarta el registro. Un monto inválido contamina todos los agregados que lo toquen.',
            self::InvalidQuantity => 'Se descarta el registro. Asumir una unidad haría que los hechos por producto dejaran de cuadrar contra los de venta, y nadie se enteraría. Si el campo directamente no viene, se asume una unidad: ausente y presente-pero-ilegible no son el mismo problema.',
            self::UnknownCurrency => 'Se descarta el registro. Convertirlo con una tasa inventada sería peor que no tenerlo.',
            self::UnknownStatus => 'Se descarta el registro. Antes se intenta mapear sinónimos habituales (complete, canceled, refunded).',
            self::MalformedDate => 'Se prueban varios formatos y zonas horarias. Si ninguno interpreta, se descarta el registro.',
            self::EmailInvalid => 'Se CONSERVA el cliente con el email marcado como inválido. Se anota el rechazo a nivel de campo, no de registro.',
            self::StaleUpdate => 'Se ignora la actualización y se conserva el estado más nuevo. Queda registrada en el historial para poder investigarla.',
            self::UnreadableUpdateTimestamp => 'Se CONSERVA la orden y se asume el momento de la venta como marca de actualización. Se anota el motivo real: sin él, la orden se descartaría después como `stale_update` y el diagnóstico culparía al orden de llegada en vez de a la fecha ilegible.',
            self::UnsupportedEntity => 'El registro queda guardado en crudo pero NO se aplica a la zona curada, y se cuenta como rechazado, nunca como aceptado. Sólo se normalizan órdenes, que ya traen su comprador y sus renglones anidados.',
        };
    }
}
