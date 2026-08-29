<?php

declare(strict_types=1);

namespace App\Http\Dashboard\Controllers;

use App\Domain\Tenancy\Models\Tenant;
use Database\Seeders\ControlPlaneSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Consola para explorar la API a mano.
 *
 * Es una herramienta de evaluacion y desarrollo, no parte del producto, y
 * arrastra por eso TRES candados en vez de uno:
 *
 *   1. Fuera del entorno `local` no existe: 404, no 403. Un 403 confirmaria
 *      que la ruta existe.
 *   2. Exige sesion y rol de administrador (la misma capacidad que gobierna
 *      /usuarios). La pagina entrega una credencial de API, y esa credencial
 *      salta por encima del control de roles del dashboard: quien la tiene
 *      lee el padron completo por HTTP. Darsela a un analista seria anular
 *      la Parte 2 entera desde una pantalla auxiliar.
 *   3. Muestra la clave del tenant de QUIEN MIRA, nunca la de otro. Un
 *      administrador de luna no tiene por que ver la credencial de andes;
 *      esa fuga seria peor que la del punto anterior, porque cruza tenants.
 *
 * La version anterior de esta clase solo tenia el candado 1, con el compose
 * fijando APP_ENV=local. Era un bypass del control de roles servido en la
 * misma aplicacion que ese control protege.
 *
 * El contrato formal de la API esta en docs/openapi.yaml. Esta pagina es para
 * verlo funcionando; el spec es para leerlo.
 */
class ApiConsoleController extends Controller
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->app->environment('local')) {
            throw new NotFoundHttpException;
        }

        $tenant = $request->user()?->tenant;

        // Sin tenant resuelto no hay clave que mostrar. Es la misma respuesta
        // que fuera de local, por la misma razon.
        if (! $tenant instanceof Tenant) {
            throw new NotFoundHttpException;
        }

        return response()->view('api-console', [
            'tenants' => [[
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'currency' => $tenant->reporting_currency,
                'timezone' => $tenant->timezone,
                'strategy' => $tenant->isolation_strategy->value,
                'apiKey' => ControlPlaneSeeder::demoApiKey($tenant->slug),
            ]],
        ]);
    }
}
