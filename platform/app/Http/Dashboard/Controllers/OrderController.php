<?php

declare(strict_types=1);

namespace App\Http\Dashboard\Controllers;

use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\V1\Resources\CustomerDetailResource;
use App\Http\Api\V1\Resources\OrderResource;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Detalle de una orden. Customer Support y administrador.
 *
 * Es una pantalla propia y no un desplegable dentro de la ficha del cliente
 * porque acá vive la traza que el resto del producto no muestra en ningún
 * lado: el historial de estados, la tasa con la que se convirtió el monto, y
 * los tres tiempos que separan cuándo compró el cliente de cuándo nos
 * enteramos nosotros. Enterrar eso en un acordeón era tener el dato y no
 * poder mostrarlo.
 *
 * Va bajo la misma capacidad que /clientes y no bajo una propia: quien puede
 * ver la ficha de un comprador puede ver sus compras. Una capacidad nueva
 * para el mismo dato sería una tabla de permisos que miente.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function show(string $customer, string $order): Response
    {
        // La orden se busca SIEMPRE acotada al cliente de la URL. Sin ese
        // `where`, /clientes/{cualquiera}/ordenes/{id} devolvería la orden
        // igual y el cliente de la ruta sería decorativo.
        $model = Order::query()
            ->with(['items.product', 'statusHistory', 'customer.metrics'])
            ->where('customer_id', $customer)
            ->find($order);

        // Tres situaciones distintas, una sola respuesta: la orden no existe,
        // es de otro tenant (invisible por Row-Level Security antes de que
        // este código la vea), o existe pero es de otro cliente. Distinguirlas
        // confirmaría desde afuera qué órdenes existen y de quién son.
        if ($model === null || $model->customer === null) {
            throw new NotFoundHttpException('La orden no existe.');
        }

        $tenant = $this->context->currentOrFail();

        return Inertia::render('Dashboard/Customers/Orders/Show', [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'currency' => $tenant->reporting_currency,
                'timezone' => $tenant->timezone,
            ],
            'currency' => $tenant->reporting_currency,

            // El cliente viaja sin sus órdenes: la relación no está cargada,
            // así que el resource la omite sola. Esta pantalla muestra UNA
            // orden, no el historial de compras de quien la hizo.
            'customer' => (new CustomerDetailResource($model->customer))->resolve(request()),
            'order' => (new OrderResource($model))->resolve(request()),
        ]);
    }
}
