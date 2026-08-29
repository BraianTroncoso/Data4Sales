<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

/**
 * De donde viene un lote. Son las dos situaciones que el enunciado pide
 * poder simular, y se distinguen porque no se comportan igual:
 *
 * La carga historica trae meses de una sola vez, mayormente altas, y ensucia
 * cientos de dias de agregados a la vez. El flujo diario trae poco volumen
 * pero mucha actualizacion de estado, y casi siempre ensucia el dia de hoy.
 *
 * Guardarlo permite medir cuanto reproceso genera cada uno, que es
 * informacion de producto y no solo de operacion.
 */
enum IngestSource: string
{
    case HistoricalBackfill = 'historical_backfill';
    case DailyStream = 'daily_stream';
}
