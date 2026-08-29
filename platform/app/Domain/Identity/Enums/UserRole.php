<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Roles del dashboard, tal como los define el enunciado.
 *
 * El acceso siempre es la interseccion de dos cosas: el rol dice QUE puede
 * ver, el tenant dice DE QUIEN. Un administrador tiene todos los permisos
 * dentro de su tenant y ninguno fuera de el.
 */
enum UserRole: string
{
    /** Estadisticas agregadas. Sin acceso a datos personales de compradores. */
    case Analyst = 'analyst';

    /** Datos de clientes para atender casos. Sin acceso a estadisticas. */
    case Support = 'support';

    /** Todo lo anterior, mas la gestion de usuarios de su propio tenant. */
    case Admin = 'admin';

    /**
     * Los agregados no exponen a ninguna persona identificable, por eso el
     * analista los ve y el soporte no los necesita.
     */
    public function canViewStatistics(): bool
    {
        return $this === self::Analyst || $this === self::Admin;
    }

    /**
     * Esta es la puerta a datos personales de compradores. Se concede solo
     * a quien atiende casos y a quien administra.
     */
    public function canViewCustomerData(): bool
    {
        return $this === self::Support || $this === self::Admin;
    }

    public function canManageUsers(): bool
    {
        return $this === self::Admin;
    }

    public function label(): string
    {
        return match ($this) {
            self::Analyst => 'Analista',
            self::Support => 'Customer Support',
            self::Admin => 'Administrador',
        };
    }
}
