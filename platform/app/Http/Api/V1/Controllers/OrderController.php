<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Http\Api\V1\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Listado y detalle de ordenes.
 *
 * Los filtros de fecha van contra `placed_on`, la columna precalculada con
 * el dia del TENANT, no contra `placed_at`. Filtrar por el timestamp
 * obligaria a aplicar una conversion de zona horaria sobre cada fila y
 * anularia el indice; y ademas daria resultados que no coinciden con lo que
 * el retailer considera "el martes".
 */
class OrderController extends Controller
{
    private const PER_PAGE_MAX = 100;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::query();

        if ($from = $request->query('from')) {
            $query->whereDate('placed_on', '>=', (string) $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('placed_on', '<=', (string) $to);
        }

        if ($status = $request->query('status')) {
            // Se aceptan varios estados separados por coma: la pantalla de
            // soporte suele querer "todo lo que todavía no llegó".
            $statuses = array_values(array_filter(
                array_map('trim', explode(',', (string) $status)),
                fn (string $value) => OrderStatus::tryFrom($value) !== null,
            ));

            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', (string) $customerId);
        }

        // Sólo ventas netas: excluye canceladas y devueltas.
        if ($request->boolean('net_only')) {
            $query->netRevenue();
        }

        // Órdenes que llegaron tarde. Es un filtro de diagnóstico, no de
        // negocio: sirve para responder "qué se movió del pasado esta semana".
        if ($request->boolean('late_arrivals')) {
            $query->whereRaw('received_at::date - placed_at::date > 1');
        }

        $perPage = min((int) $request->integer('per_page', 25) ?: 25, self::PER_PAGE_MAX);

        return OrderResource::collection(
            $query->with('items.product')
                ->orderByDesc('placed_at')
                ->orderBy('id')
                ->cursorPaginate($perPage)
                ->withQueryString()
        );
    }

    public function show(string $order): JsonResponse
    {
        $model = Order::query()
            ->with(['items.product', 'statusHistory', 'customer'])
            ->find($order);

        if ($model === null) {
            return new JsonResponse([
                'error' => ['code' => 'not_found', 'message' => 'La orden no existe.'],
            ], 404);
        }

        return (new OrderResource($model))->response();
    }
}
