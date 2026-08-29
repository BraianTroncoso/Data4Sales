<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl;

use InvalidArgumentException;

/**
 * La UNICA definicion de que significa cada agregado del DSL.
 *
 * Existe porque las dos implementaciones ya divergieron. `SqlCompiler`
 * comparaba el promedio contra el valor exacto y `ExplainCompiler` contra el
 * mismo valor redondeado a entero: un cliente con promedio 99.999,6 y umbral
 * 100.000 quedaba AFUERA de la audiencia y, en la pantalla que explica por
 * que, "cumplia". Es el peor lugar posible para una contradiccion, porque
 * aparece justo cuando alguien pregunta por que recibio —o no recibio— un
 * correo, que es la pregunta que este modulo existe para contestar.
 *
 * Tener dos implementaciones de "que significa esta condicion" garantiza que
 * en algun momento se contradigan. La unica defensa estructural es que haya
 * una sola definicion y que las dos formas de evaluarla la lean de aca: si
 * alguien cambia el criterio, lo cambia para las dos a la vez o no lo cambia.
 *
 * SEMANTICA ELEGIDA: el promedio NO se redondea, ni en SQL ni al traerlo a
 * PHP. Un umbral es una afirmacion de negocio —"gasta al menos cien mil"— y
 * redondear hace que alguien que gasta 99.999,6 la cumpla. En un motor que le
 * escribe a personas, el falso positivo (mandarle a quien no corresponde)
 * cuesta mas caro que el falso negativo, y es el mismo criterio que el resto
 * del sistema ya aplica cada vez que prefiere no enviar antes que enviar mal.
 */
final class Aggregates
{
    /**
     * Expresion SQL de cada agregado. `o` es el alias de `orders`, que las
     * dos consultas respetan.
     *
     * @var array<string, string>
     */
    public const SQL = [
        'count' => 'COUNT(*)',
        'sum_reporting_minor' => 'COALESCE(SUM(o.reporting_amount_minor), 0)',
        'avg_reporting_minor' => 'COALESCE(AVG(o.reporting_amount_minor), 0)',
    ];

    /**
     * El agregado que no es entero. Se nombra una sola vez para que el
     * criterio de conversion no se replique a mano en cada consumidor.
     */
    private const FRACTIONAL = ['avg_reporting_minor'];

    /**
     * Expresion de un agregado, para usar en un HAVING.
     *
     * El nombre ya viene validado por `ConditionParser` contra la misma
     * lista; la excepcion esta para que un consumidor nuevo que se saltee el
     * parser falle fuerte en vez de compilar SQL con un hueco.
     */
    public static function expression(string $agg): string
    {
        return self::SQL[$agg] ?? throw new InvalidArgumentException(
            "Agregado [{$agg}] desconocido. Disponibles: ".implode(', ', array_keys(self::SQL)).'.'
        );
    }

    /**
     * Lista de proyeccion, con el nombre del agregado como alias.
     *
     * Que el alias sea el nombre del agregado del DSL no es cosmetico: es lo
     * que permite que el explicador lea `$row->{$clause['agg']}` sin una
     * tabla de traduccion propia, que seria el proximo lugar donde las dos
     * implementaciones se separan.
     */
    public static function selectList(): string
    {
        return implode(",\n                   ", array_map(
            static fn (string $alias, string $sql): string => "{$sql} AS {$alias}",
            array_keys(self::SQL),
            array_values(self::SQL),
        ));
    }

    /**
     * Trae el valor de PostgreSQL a PHP sin cambiar el criterio.
     *
     * Castear el promedio a `int` seria la misma perdida de informacion que
     * redondearlo en SQL, y volveria a abrir la divergencia por el otro lado:
     * el HAVING compararia 99.999,6 y PHP compararia 99.999.
     */
    public static function toPhp(string $agg, mixed $raw): int|float
    {
        return in_array($agg, self::FRACTIONAL, strict: true)
            ? (float) $raw
            : (int) $raw;
    }
}
