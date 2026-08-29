<?php

declare(strict_types=1);

namespace App\Http\Dashboard\Controllers;

use App\Domain\Analytics\Queries\MetricsQuery;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de estadísticas. Analista y administrador.
 *
 * No expone ni un solo dato personal de comprador: todo lo que viaja son
 * agregados. Por eso el analista puede verla y no puede ver el padrón — el
 * corte de acceso sigue el corte del dato, no una jerarquía inventada.
 */
class StatisticsController extends Controller
{
    private const TOP_LIMIT = 10;

    public function __construct(
        private readonly MetricsQuery $metrics,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request): Response
    {
        $tenant = $this->context->currentOrFail();

        [$from, $to] = $this->metrics->resolveRange(
            $request->query('from'),
            $request->query('to'),
        );

        $granularity = MetricsQuery::granularity($request->query('granularity'));
        $byCategory = $request->query('by') !== 'product';

        return Inertia::render('Dashboard/Statistics', [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'currency' => $tenant->reporting_currency,
                'timezone' => $tenant->timezone,
            ],
            'range' => ['from' => $from, 'to' => $to, 'granularity' => $granularity],
            // Los límites del dataset, para que el selector no ofrezca rangos
            // vacíos y para poder mostrar "todo" como opción real.
            'bounds' => $this->metrics->bounds(),
            'groupedBy' => $byCategory ? 'category' : 'product',

            'series' => $this->metrics->salesSeries($from, $to, $granularity),
            'segments' => $this->metrics->segments(),
            'topProducts' => $this->metrics->topProducts($from, $to, self::TOP_LIMIT, $byCategory),
            'retention' => $this->metrics->retention(),
            'freshness' => $this->metrics->freshness(),
        ]);
    }
}
