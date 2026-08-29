<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use Illuminate\Routing\Controller as BaseController;

/**
 * Base de los controladores de la API publica.
 *
 * Separada de la del dashboard: las dos superficies tienen autenticacion,
 * formato de respuesta y modelo de amenaza distintos, y compartir base las
 * empujaria a parecerse mas de lo que conviene.
 */
abstract class Controller extends BaseController {}
