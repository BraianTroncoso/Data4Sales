<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Queries\CustomerSearch;
use App\Http\Api\V1\Resources\CustomerDetailResource;
use App\Http\Api\V1\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Padron de compradores.
 *
 * Esta pantalla expone datos personales, asi que todo lo que se decide aca
 * pesa: que se puede buscar, cuanto se devuelve por pagina, y que campos
 * viajan en el listado contra el detalle. El listado NO incluye el email
 * completo ni el telefono: quien esta buscando un caso puntual no necesita
 * el padron entero con datos de contacto, y una exportacion accidental de
 * mil filas deja de ser una fuga de contactos.
 */
class CustomerController extends Controller
{
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly CustomerSearch $search,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->search->build($request->query());

        // Paginacion por cursor y no por offset. Con cientos de miles de
        // filas, `OFFSET 50000` obliga al motor a contar y descartar
        // cincuenta mil filas antes de devolver nada; el cursor salta directo
        // por el indice. El costo es que no hay "ir a la pagina 7", que en
        // una pantalla de soporte no le sirve a nadie.
        $perPage = min((int) $request->integer('per_page', 25) ?: 25, self::PER_PAGE_MAX);

        return CustomerResource::collection(
            $query->cursorPaginate($perPage)->withQueryString()
        );
    }

    public function show(string $customer): JsonResponse
    {
        $model = Customer::query()
            ->with(['metrics', 'orders' => fn ($q) => $q->with('items.product')->latest('placed_at')->limit(100)])
            ->find($customer);

        if ($model === null) {
            // El cliente de otro tenant es invisible por Row-Level Security
            // antes de que este codigo lo vea, asi que la respuesta es la
            // misma que para uno inexistente y no filtra nada.
            return new JsonResponse([
                'error' => ['code' => 'not_found', 'message' => 'El cliente no existe.'],
            ], 404);
        }

        return (new CustomerDetailResource($model))->response();
    }
}
