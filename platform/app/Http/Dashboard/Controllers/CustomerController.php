<?php

declare(strict_types=1);

namespace App\Http\Dashboard\Controllers;

use App\Domain\Analytics\Enums\RfmSegment;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Queries\CustomerSearch;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\V1\Resources\CustomerDetailResource;
use App\Http\Api\V1\Resources\CustomerResource;
use App\Http\Api\V1\Resources\OrderResource;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pantalla de datos de clientes. Customer Support y administrador.
 *
 * Expone datos personales de compradores, así que hay una decisión
 * deliberada en cómo se reparten:
 *
 *   LISTADO  email enmascarado, sin teléfono. Un listado se pagina, se
 *            exporta y se copia; que no lleve contacto completo hace que una
 *            fuga de mil filas no sea una fuga de mil contactos.
 *   DETALLE  contacto completo. Es la pantalla donde un agente atiende un
 *            caso puntual, y ocultarle el email al que tiene que responder
 *            sería seguridad de utilería.
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Las únicas densidades que acepta el listado.
     *
     * Lista blanca y no un entero libre: `per_page` viaja en la URL, y sin
     * tope alguien pide cien mil y se lleva el padrón entero en una sola
     * respuesta — que es justo lo que el enmascarado del email intenta evitar.
     *
     * @var list<int>
     */
    private const PER_PAGE_OPTIONS = [25, 50, 100];

    public function __construct(
        private readonly CustomerSearch $search,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        // Fuera de la lista blanca se vuelve al default en silencio, sin 422:
        // un `per_page` raro en la URL es un enlace viejo o un dedo, no un
        // ataque, y romperle la pantalla al agente no arregla nada.
        $perPage = in_array($request->integer('per_page'), self::PER_PAGE_OPTIONS, strict: true)
            ? $request->integer('per_page')
            : self::PER_PAGE;

        $filters = [
            'q' => (string) $request->query('q', ''),
            'segment' => $request->query('segment'),
            'country' => $request->query('country'),
            'email_status' => $request->query('email_status'),
            'sort' => $request->query('sort', 'last_seen_at'),
            'direction' => $request->query('direction', 'desc'),
            // Vuelve resuelto, no como llegó: la pantalla tiene que poder
            // marcar la opción activa aunque la URL no traiga nada.
            'per_page' => $perPage,
        ];

        $page = $this->search->build($filters)
            ->cursorPaginate($perPage)
            ->withQueryString();

        return Inertia::render('Dashboard/Customers/Index', [
            'tenant' => $this->tenantProps(),
            'currency' => $this->context->currentOrFail()->reporting_currency,
            'filters' => $filters,
            'segmentOptions' => array_map(
                static fn (RfmSegment $s) => ['value' => $s->value, 'label' => $s->label()],
                RfmSegment::cases(),
            ),
            // Los países que este tenant tiene de verdad. Con uno solo el
            // filtro sobra y la pantalla lo esconde; la decisión se toma acá
            // con el padrón entero y no allá con la página visible.
            'countryOptions' => $this->search->countries(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'customers' => [
                'data' => CustomerResource::collection($page->items())->resolve(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }

    public function show(string $customer): Response
    {
        $model = Customer::query()
            ->with(['metrics', 'orders' => fn ($q) => $q->with(['items.product', 'statusHistory'])->latest('placed_at')->limit(200)])
            ->find($customer);

        // Un cliente de otro tenant es invisible por Row-Level Security antes
        // de que este código lo vea, así que este 404 y el de un cliente
        // inexistente son indistinguibles. No filtra que exista.
        if ($model === null) {
            throw new NotFoundHttpException('El cliente no existe.');
        }

        $customer = (new CustomerDetailResource($model))->resolve(request());

        // El historial viaja como prop propia y no anidado dentro del cliente:
        // la pantalla los trata como dos bloques distintos, y mandarlo dos
        // veces sería duplicar la parte más pesada de la respuesta.
        unset($customer['orders']);

        return Inertia::render('Dashboard/Customers/Show', [
            'tenant' => $this->tenantProps(),
            'currency' => $this->context->currentOrFail()->reporting_currency,
            'customer' => $customer,
            'orders' => OrderResource::collection($model->orders)->resolve(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function tenantProps(): array
    {
        $tenant = $this->context->currentOrFail();

        return [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'currency' => $tenant->reporting_currency,
            'timezone' => $tenant->timezone,
        ];
    }
}
