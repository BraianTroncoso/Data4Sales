<?php

declare(strict_types=1);

use App\Domain\Emitter\DatasetGenerator;
use App\Domain\Emitter\DatasetProfile;
use Carbon\CarbonImmutable;

function perfil(): DatasetProfile
{
    return DatasetProfile::forTenant('luna', CarbonImmutable::parse('2026-08-27'));
}

it('produce exactamente el mismo dataset con la misma semilla', function () {
    $primera = iterator_to_array((new DatasetGenerator(perfil(), 12345))->historicalOrders(200));
    $segunda = iterator_to_array((new DatasetGenerator(perfil(), 12345))->historicalOrders(200));

    // Reproducible de verdad. Se usa un motor con semilla explícita en vez de
    // las funciones globales de PHP justamente para esto: las globales
    // comparten estado con todo el proceso, y basta que otra librería consuma
    // un número al azar para que el dataset deje de ser el mismo.
    expect($segunda)->toBe($primera);
});

it('produce datasets distintos con semillas distintas', function () {
    $a = iterator_to_array((new DatasetGenerator(perfil(), 1))->historicalOrders(100));
    $b = iterator_to_array((new DatasetGenerator(perfil(), 2))->historicalOrders(100));

    expect($b)->not->toBe($a);
});

it('nunca explota, sin importar cómo se combinen las suciedades', function (int $seed) {
    // Las suciedades se aplican en cadena sobre el mismo registro: una puede
    // borrar el campo que la siguiente quiere modificar. Con proporciones
    // bajas esa combinación aparece sólo con ciertas semillas, y sin este
    // test se descubre corriendo el emisor en serio y no acá.
    $orders = iterator_to_array((new DatasetGenerator(perfil(), $seed))->historicalOrders(400));

    expect($orders)->not->toBeEmpty();
})->with([1, 42, 999, 12345, 3055375783, 3276090107]);

it('cubre más de tres meses de historia continua', function () {
    $orders = iterator_to_array((new DatasetGenerator(perfil(), 7))->historicalOrders(1500));

    $fechas = [];

    foreach ($orders as $order) {
        $raw = $order['placed_at'] ?? null;

        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m) === 1) {
            $fechas[] = $m[0];
        }
    }

    sort($fechas);

    $desde = CarbonImmutable::parse($fechas[0]);
    $hasta = CarbonImmutable::parse(end($fechas));

    expect($desde->diffInDays($hasta))->toBeGreaterThan(90);
});

it('inyecta cada tipo de suciedad que exige el enunciado', function () {
    $orders = iterator_to_array((new DatasetGenerator(perfil(), 20260827))->historicalOrders(3000));

    $monedas = [];
    $sinTotal = 0;
    $vacios = 0;
    $ajenos = 0;
    $emailsInvalidos = 0;
    $identificadores = [];

    foreach ($orders as $order) {
        $monedas[$order['currency'] ?? 'ausente'] = true;
        $identificadores[] = $order['order_id'] ?? null;

        if (! array_key_exists('total', $order)) {
            $sinTotal++;
        }

        if (is_string($order['total'] ?? null) && trim($order['total']) === '') {
            $vacios++;
        }

        if (isset($order['tenant_id'])) {
            $ajenos++;
        }

        $email = $order['customer']['email'] ?? '';

        if (! str_contains($email, '@') || str_contains($email, ' ') || str_ends_with($email, '@localhost')) {
            $emailsInvalidos++;
        }
    }

    // Duplicados exactos: el mismo identificador aparece más de una vez.
    $repetidos = count($identificadores) - count(array_unique(array_filter($identificadores)));

    expect(count($monedas))->toBeGreaterThan(1)
        ->and($sinTotal)->toBeGreaterThan(0)
        ->and($vacios)->toBeGreaterThan(0)
        ->and($ajenos)->toBeGreaterThan(0)
        ->and($emailsInvalidos)->toBeGreaterThan(0)
        ->and($repetidos)->toBeGreaterThan(0);
});

it('genera actualizaciones de órdenes existentes en el flujo diario', function () {
    $orders = iterator_to_array((new DatasetGenerator(perfil(), 55))->dailyOrders(300, existingOrders: 1000));

    // El flujo diario tiene que traer actualizaciones de órdenes que ya
    // existen, no sólo altas: es lo que ejercita la guarda de versión.
    expect($orders)->not->toBeEmpty()
        ->and(array_filter(array_column($orders, 'order_id')))->not->toBeEmpty();
});

it('desplaza la numeración de órdenes para poder repartir una carga entre procesos', function () {
    $perfil = DatasetProfile::forTenant('mega');

    // Misma semilla, distinto desplazamiento: es el caso de dos procesos
    // cargando el mismo tenant en paralelo.
    $primero = iterator_to_array((new DatasetGenerator($perfil, 7))->historicalOrders(50), preserve_keys: false);
    $segundo = iterator_to_array((new DatasetGenerator($perfil, 7))->historicalOrders(50, startAt: 51), preserve_keys: false);

    $ids = fn (array $registros) => array_values(array_unique(array_column($registros, 'order_id')));

    // Sin desplazamiento los dos rangos serían idénticos y el segundo proceso
    // entraría entero como duplicado: la ingesta lo rechazaría bien y el
    // volumen no crecería ni un poco.
    expect(array_intersect($ids($primero), $ids($segundo)))->toBe([]);
    expect(max($ids($primero)))->toBeLessThan(min($ids($segundo)));
});

it('la semilla sola no alcanza para repartir: cambia el contenido, no el identificador', function () {
    $perfil = DatasetProfile::forTenant('mega');

    $a = iterator_to_array((new DatasetGenerator($perfil, 1))->historicalOrders(20), preserve_keys: false);
    $b = iterator_to_array((new DatasetGenerator($perfil, 2))->historicalOrders(20), preserve_keys: false);

    // Es la trampa que documenta el README: con semillas distintas parece que
    // se está cargando el doble, y en realidad se está reenviando el mismo
    // rango de identificadores. La suciedad inyectada difiere, así que los
    // conjuntos no son idénticos — pero se solapan casi por completo, que es
    // exactamente lo que hace que el segundo proceso no aporte volumen.
    $ids = fn (array $registros) => array_unique(array_filter(array_column($registros, 'order_id')));

    $comunes = array_intersect($ids($a), $ids($b));

    expect(count($comunes))->toBeGreaterThan((int) (0.8 * count($ids($a))));
});
