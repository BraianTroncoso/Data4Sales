<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * Donde viven los datos de un tenant.
 *
 * Las dos estrategias conviven en el mismo sistema y sobre el mismo
 * esquema. Todo el codigo de dominio es indiferente a cual esta en juego:
 * el unico lugar que las distingue es TenantConnectionResolver.
 */
enum IsolationStrategy: string
{
    /**
     * Cluster compartido. El aislamiento lo garantiza Row-Level Security en
     * PostgreSQL. Es el valor por defecto y el que escala a miles de
     * tenants sin multiplicar conexiones ni migraciones.
     */
    case Shared = 'shared';

    /**
     * Base propia. Se usa cuando un tenant lo exige por contrato, o cuando
     * su volumen justifica dejar de competir por recursos con el resto.
     * El esquema es identico al compartido, por eso promover es mover
     * datos y no transformar estructura.
     */
    case Dedicated = 'dedicated';
}
