<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Actions;

use App\Domain\Analytics\Queries\MetricsQuery;
use App\Domain\Outreach\Dsl\CompiledQuery;
use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Dsl\SqlCompiler;
use App\Domain\Outreach\Enums\DecisionReason;
use App\Domain\Outreach\Models\AudienceDefinition;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve una condicion contra el dataset y congela la audiencia.
 *
 * Todo lo que decide se decide contra el `as_of` de la ejecucion, nunca
 * contra "ahora". Si un worker retoma la tanda diez minutos despues, la
 * ventana "ultimos 90 dias" tiene que seguir significando lo mismo; si no,
 * dos reintentos de la misma ejecucion resolverian audiencias distintas y
 * nadie podria explicar la diferencia.
 *
 * El congelamiento hace tres cosas en una sola pasada, sobre una tabla
 * temporal:
 *
 *   1. Deduplica por direccion de correo. Dos clientes pueden compartir email
 *      (D-08), y sin esto la misma persona recibe dos copias.
 *   2. Excluye las direcciones suprimidas.
 *   3. Registra POR QUE quedo afuera cada uno de los excluidos.
 *
 * El paso 3 sólo cubre las exclusiones POSTERIORES a la condicion, que estan
 * acotadas por el tamano de la audiencia. El no-cumplimiento de la condicion
 * se reconstruye a demanda: registrar por que cada uno de los ciento
 * cincuenta mil clientes que NO entraron no entro seria escribir mas filas de
 * exclusion que de audiencia, en cada ejecucion.
 */
final class ResolveAudience
{
    /**
     * Umbral de frescura de las metricas derivadas.
     *
     * Si los agregados estan mas atrasados que esto y la condicion los usa,
     * la ejecucion avisa. Mandarle "hace 50 dias que no comprás" a alguien
     * que compro ayer es el tipo de error que cuesta un cliente, y viene de
     * un desfasaje que para el dashboard seria irrelevante.
     */
    private const FRESHNESS_THRESHOLD_SECONDS = 900;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ConditionParser $parser,
        private readonly SqlCompiler $compiler,
        private readonly MetricsQuery $metrics,
    ) {}

    /**
     * Compila la condicion sin tocar nada. Devuelve el conteo y el SQL.
     *
     * @return array{count: int, query: CompiledQuery, as_of: CarbonImmutable, metrics_lag_seconds: int|null, stale: bool}
     */
    public function preview(AudienceDefinition $definition, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $node = $this->parser->parse($definition->dsl);
        $query = $this->compiler->compile($node, $this->context->currentOrFail()->id, $asOf);

        $count = (int) DB::connection($this->connection())->selectOne(
            "SELECT COUNT(*) AS total FROM ({$query->sql}) AS audiencia",
            $query->bindings,
        )->total;

        $lag = $this->metricsLagSeconds();

        return [
            'count' => $count,
            'query' => $query,
            'as_of' => $asOf,
            'metrics_lag_seconds' => $lag,
            'stale' => $lag !== null && $lag > self::FRESHNESS_THRESHOLD_SECONDS,
        ];
    }

    /**
     * Congela la audiencia de una ejecucion.
     *
     * Reejecutarlo sobre la misma ejecucion no duplica NADA, y esa palabra
     * costo un bug: los indices unicos de `campaign_recipients` cubrian los
     * destinatarios, pero las decisiones se insertaban sin indice unico y sin
     * ON CONFLICT, asi que un segundo congelamiento las duplicaba una por una.
     * La garantia se enunciaba sobre una tabla y se leia como si valiera para
     * las tres. Ahora vale para las tres, y la unicidad la impone el motor
     * (ver la migracion 2026_01_02_010000).
     *
     * @return array{included: int, suppressed: int, duplicates: int}
     */
    public function freeze(CampaignRun $run, CompiledQuery $query): array
    {
        $connection = $this->connection();
        $tenantId = $this->context->currentOrFail()->id;

        return DB::connection($connection)->transaction(function () use ($connection, $run, $query, $tenantId): array {
            $db = DB::connection($connection);

            // Tabla temporal: los candidatos se materializan UNA vez y las
            // tres consultas siguientes leen de ahí. Sin esto habría que
            // ejecutar la condición tres veces, y con el tenant grande eso
            // es tres veces el trabajo más caro de todo el proceso.
            $db->statement("CREATE TEMP TABLE candidatos ON COMMIT DROP AS {$query->sql}", $query->bindings);

            $db->statement(
                'ALTER TABLE candidatos ADD COLUMN email_hash char(64)'
            );
            $db->statement(
                "UPDATE candidatos SET email_hash = encode(sha256(lower(trim(email))::bytea), 'hex')"
            );

            $total = (int) $db->selectOne('SELECT COUNT(*) AS n FROM candidatos')->n;

            // DISTINCT ON con orden explícito: determinista. Sin el ORDER BY,
            // PostgreSQL puede elegir cualquiera de las filas duplicadas, y
            // dos ejecuciones de la misma condición elegirían clientes
            // distintos para la misma dirección.
            $db->statement(
                <<<'SQL'
                WITH elegidos AS (
                    SELECT DISTINCT ON (email_hash) customer_id, email_hash
                    FROM candidatos
                    ORDER BY email_hash, customer_id
                )
                INSERT INTO campaign_recipients (id, tenant_id, run_id, customer_id, email_hash, entered_at)
                SELECT gen_random_uuid(), ?, ?, e.customer_id, e.email_hash, NOW()
                FROM elegidos e
                WHERE NOT EXISTS (
                    SELECT 1 FROM suppressions s
                    WHERE s.tenant_id = ?
                      AND s.email_hash = e.email_hash
                      AND (s.expires_at IS NULL OR s.expires_at > NOW())
                )
                ON CONFLICT DO NOTHING
                SQL,
                [$tenantId, $run->id, $tenantId],
            );

            $suprimidos = $this->recordExclusions(
                $db, $run, $tenantId,
                <<<'SQL'
                SELECT DISTINCT ON (c.email_hash) c.customer_id
                FROM candidatos c
                JOIN suppressions s
                  ON s.tenant_id = ? AND s.email_hash = c.email_hash
                 AND (s.expires_at IS NULL OR s.expires_at > NOW())
                ORDER BY c.email_hash, c.customer_id
                SQL,
                [$tenantId],
                DecisionReason::Suppressed,
            );

            $duplicados = $this->recordExclusions(
                $db, $run, $tenantId,
                <<<'SQL'
                SELECT c.customer_id
                FROM candidatos c
                WHERE c.customer_id NOT IN (
                    SELECT DISTINCT ON (email_hash) customer_id
                    FROM candidatos
                    ORDER BY email_hash, customer_id
                )
                SQL,
                [],
                DecisionReason::DuplicateEmail,
            );

            // Los incluidos también dejan rastro: "por qué SÍ recibió" es la
            // otra mitad de la pregunta que el enunciado pide poder responder.
            $db->statement(
                <<<'SQL'
                INSERT INTO recipient_decisions (tenant_id, run_id, customer_id, decision, reason_code, node_id, decided_at)
                SELECT ?, ?, r.customer_id, 'included', ?, 'root', NOW()
                FROM campaign_recipients r
                WHERE r.run_id = ?
                ON CONFLICT DO NOTHING
                SQL,
                [$tenantId, $run->id, DecisionReason::MatchedCondition->value, $run->id],
            );

            // El tamano de la audiencia se DERIVA de la tabla, no se cuenta lo
            // insertado en esta pasada.
            //
            // Contar filas insertadas era correcto solo la primera vez: con
            // `ON CONFLICT DO NOTHING ... RETURNING`, el segundo congelamiento
            // no devuelve ninguna fila y escribia `audience_size = 0` sobre una
            // audiencia de 183 personas. El numero quedaba mintiendo en la
            // pantalla de la ejecucion y en cualquier reporte que lo lea,
            // mientras los destinatarios seguian ahi.
            //
            // `audience_size` es una propiedad del estado congelado, no del
            // trabajo que hizo esta invocacion. Un COUNT lo dice siempre bien,
            // no importa cuantas veces se corra.
            $incluidos = (int) $db->selectOne(
                'SELECT COUNT(*) AS n FROM campaign_recipients WHERE run_id = ?',
                [$run->id],
            )->n;

            $run->update([
                'status' => 'resolved',
                'audience_size' => $incluidos,
            ]);

            return [
                'included' => $incluidos,
                'suppressed' => $suprimidos,
                'duplicates' => $duplicados,
                'candidates' => $total,
            ];
        });
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function recordExclusions(
        ConnectionInterface $db,
        CampaignRun $run,
        string $tenantId,
        string $selectSql,
        array $bindings,
        DecisionReason $reason,
    ): int {
        $rows = $db->select($selectSql, $bindings);

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $row) {
                $values[] = '(?,?,?,?,?,NOW())';
                array_push($params, $tenantId, $run->id, $row->customer_id, 'excluded', $reason->value);
            }

            // Mismo motivo que arriba: sin ON CONFLICT, volver a congelar
            // escribia una copia de cada exclusion. Y "por que quedo afuera"
            // repetido tres veces no es mas informacion, es ruido en la unica
            // tabla que existe para contestar esa pregunta.
            $db->statement(
                'INSERT INTO recipient_decisions (tenant_id, run_id, customer_id, decision, reason_code, decided_at) VALUES '
                .implode(',', $values)
                .' ON CONFLICT DO NOTHING',
                $params,
            );
        }

        return count($rows);
    }

    /**
     * Cuanto hace que los agregados estan sin recomputar.
     */
    private function metricsLagSeconds(): ?int
    {
        $freshness = $this->metrics->freshness();

        return $freshness['oldest_pending_minutes'] === null
            ? null
            : $freshness['oldest_pending_minutes'] * 60;
    }

    private function connection(): string
    {
        return $this->context->connectionName();
    }
}
