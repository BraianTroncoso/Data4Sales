<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Analytics\Queries\MetricsQuery;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Métricas agregadas de la API pública.
 *
 * El controlador es deliberadamente delgado: toda la lógica vive en
 * MetricsQuery, que comparte con el dashboard. Si el SQL estuviera acá, la
 * API y la pantalla darían números distintos para la misma pregunta apenas
 * alguien tocara uno de los dos, y nadie sabría cuál está bien.
 */
class MetricsController extends Controller
{
    private const TOP_LIMIT_MAX = 50;

    public function __construct(
        private readonly MetricsQuery $metrics,
        private readonly TenantContext $context,
    ) {}

    public function salesSeries(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $granularity = MetricsQuery::granularity($request->query('granularity'));

        return new JsonResponse([
            'range' => ['from' => $from, 'to' => $to, 'granularity' => $granularity],
            'currency' => $this->currency(),
            'series' => $this->metrics->salesSeries($from, $to, $granularity),
            'freshness' => $this->metrics->freshness(),
        ]);
    }

    public function segments(): JsonResponse
    {
        return new JsonResponse([
            'currency' => $this->currency(),
            'segments' => $this->metrics->segments(),
            'freshness' => $this->metrics->freshness(),
        ]);
    }

    public function topProducts(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $limit = min((int) $request->integer('limit', 10) ?: 10, self::TOP_LIMIT_MAX);
        $byCategory = $request->query('by') === 'category';

        return new JsonResponse([
            'range' => ['from' => $from, 'to' => $to],
            'grouped_by' => $byCategory ? 'category' : 'product',
            'currency' => $this->currency(),
            'items' => $this->metrics->topProducts($from, $to, $limit, $byCategory),
            'freshness' => $this->metrics->freshness(),
        ]);
    }

    public function retention(): JsonResponse
    {
        return new JsonResponse([
            'currency' => $this->currency(),
            ...$this->metrics->retention(),
            'freshness' => $this->metrics->freshness(),
        ]);
    }

    /**
     * @return array{string, string}
     */
    private function range(Request $request): array
    {
        return $this->metrics->resolveRange(
            $request->query('from'),
            $request->query('to'),
        );
    }

    private function currency(): string
    {
        return $this->context->currentOrFail()->reporting_currency;
    }
}
