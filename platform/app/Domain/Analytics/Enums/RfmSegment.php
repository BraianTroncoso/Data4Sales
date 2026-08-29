<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

/**
 * Segmentacion de clientes por comportamiento de compra.
 *
 * Se eligio RFM (recencia, frecuencia, valor monetario) por tres razones:
 * es el estandar de la industria retail, se calcula con los datos que ya
 * tenemos sin pedirle nada nuevo al retailer, y produce segmentos sobre los
 * que se puede ACCIONAR — que es justamente lo que necesita la Parte 3.
 *
 * Los puntajes se calculan por quintiles DENTRO de cada tenant. Un cliente
 * "campeon" de una tienda de barrio no es comparable con uno de una cadena;
 * medir contra el resto de la plataforma no significaria nada.
 */
enum RfmSegment: string
{
    case Champions = 'champions';
    case Loyal = 'loyal';
    case PotentialLoyalist = 'potential_loyalist';
    case NewCustomers = 'new_customers';
    case AtRisk = 'at_risk';
    case Hibernating = 'hibernating';
    case Lost = 'lost';

    /**
     * Deriva el segmento a partir de los puntajes de 1 a 5.
     *
     * Las reglas van de la mas especifica a la mas general, y el orden
     * importa: un cliente puede cumplir varias, y gana la primera.
     */
    public static function fromScores(int $recency, int $frequency, int $monetary): self
    {
        $value = max($frequency, $monetary);

        return match (true) {
            $recency >= 4 && $value >= 4 => self::Champions,
            $recency >= 3 && $value >= 3 => self::Loyal,
            $recency >= 4 && $value <= 2 => self::NewCustomers,
            $recency >= 3 => self::PotentialLoyalist,
            $recency === 2 && $value >= 3 => self::AtRisk,
            $recency === 2 => self::Hibernating,
            default => self::Lost,
        };
    }

    /**
     * Tabla de (r, f, m) => segmento, para usar como VALUES en SQL.
     *
     * Se GENERA desde fromScores(), no se escribe a mano. Es lo que evita el
     * problema clasico de tener la regla de negocio duplicada en PHP y en
     * SQL, divergiendo en silencio la primera vez que alguien ajusta una y
     * se olvida de la otra.
     *
     * Son 125 combinaciones: PostgreSQL las resuelve con un hash join contra
     * una tabla derivada, no recorriendo ramas por fila.
     *
     * @return list<array{int, int, int, string}>
     */
    public static function lookupTable(): array
    {
        $rows = [];

        for ($r = 1; $r <= 5; $r++) {
            for ($f = 1; $f <= 5; $f++) {
                for ($m = 1; $m <= 5; $m++) {
                    $rows[] = [$r, $f, $m, self::fromScores($r, $f, $m)->value];
                }
            }
        }

        return $rows;
    }

    /**
     * La misma tabla, ya expresada como lista VALUES de PostgreSQL.
     */
    public static function sqlValues(): string
    {
        return implode(',', array_map(
            static fn (array $row) => sprintf("(%d,%d,%d,'%s')", ...$row),
            self::lookupTable(),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Champions => 'Campeones',
            self::Loyal => 'Leales',
            self::PotentialLoyalist => 'Potenciales leales',
            self::NewCustomers => 'Nuevos',
            self::AtRisk => 'En riesgo',
            self::Hibernating => 'Hibernando',
            self::Lost => 'Perdidos',
        };
    }
}
