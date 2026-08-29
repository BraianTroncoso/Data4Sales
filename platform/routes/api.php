<?php

declare(strict_types=1);

use App\Http\Api\V1\Controllers\CustomerController;
use App\Http\Api\V1\Controllers\IngestController;
use App\Http\Api\V1\Controllers\MetricsController;
use App\Http\Api\V1\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API pública
|--------------------------------------------------------------------------
|
| Autenticación por API key, una por tenant. La clave determina el tenant y
| el tenant determina la conexión y el contexto de Row-Level Security: a
| partir del middleware, ninguna consulta de este request puede ver datos
| de otro tenant.
|
| Los alcances están separados a propósito. Una credencial de ingesta no
| debería poder leer el padrón de compradores, así que el pipeline del
| retailer puede recibir una llave que sólo escribe.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
     * El limitador va ANTES de autenticar y cuenta por credencial presentada,
     * no por IP. Antes: para que quien prueba claves al azar también quede
     * limitado — ese tráfico nunca llega a autenticarse. Por credencial:
     * los clientes son pipelines de servidor, y varias organizaciones pueden
     * salir por la misma IP.
     *
     * Ingesta y consulta tienen cupos distintos porque tienen formas de uso
     * distintas: pocos requests grandes contra muchos requests chicos.
     */

    // ── Ingesta ──────────────────────────────────────────────────
    Route::middleware(['throttle:d4s-ingest', 'api-key:ingest:write'])->group(function (): void {

        Route::post('ingest/{entity}', [IngestController::class, 'store'])
            ->name('ingest.store');
    });

    // ── Estado de la ingesta ─────────────────────────────────────
    // Consultar el resultado es lectura, no escritura: un pipeline con
    // credencial de sólo escritura igual necesita saber cómo le fue, así
    // que estas rutas aceptan cualquiera de los dos alcances.
    Route::middleware(['throttle:d4s-query', 'api-key'])->group(function (): void {

        Route::get('ingest/batches/{batch}', [IngestController::class, 'show'])
            ->name('ingest.batches.show');

        Route::get('ingest/batches/{batch}/rejects', [IngestController::class, 'rejects'])
            ->name('ingest.batches.rejects');
    });

    // ── Consulta ─────────────────────────────────────────────────
    // Exige alcance de lectura: una credencial de ingesta no puede leer el
    // padrón de compradores, que es donde vive el dato personal.
    Route::middleware(['throttle:d4s-query', 'api-key:query:read'])->group(function (): void {

        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

        // Métricas agregadas: alimentan la pantalla de estadísticas de la
        // Parte 2. Ninguna toca la tabla de órdenes.
        Route::prefix('metrics')->name('metrics.')->group(function (): void {
            Route::get('sales-series', [MetricsController::class, 'salesSeries'])->name('sales-series');
            Route::get('segments', [MetricsController::class, 'segments'])->name('segments');
            Route::get('top-products', [MetricsController::class, 'topProducts'])->name('top-products');
            Route::get('retention', [MetricsController::class, 'retention'])->name('retention');
        });
    });
});
