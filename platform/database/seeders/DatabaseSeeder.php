<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada del seed.
 *
 * Solo siembra el plano de control. Las ventas no se siembran: se ingestan
 * por la API con `php artisan d4s:emit`, que es el emisor del enunciado.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ControlPlaneSeeder::class);
    }
}
