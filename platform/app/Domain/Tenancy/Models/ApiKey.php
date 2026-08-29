<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credencial de acceso a la API, siempre atada a un tenant.
 *
 * El secreto en claro existe una sola vez: en el momento en que se genera.
 * Despues solo queda su hash. Si se pierde, se revoca y se emite otra; no
 * hay forma de recuperarlo, y esa imposibilidad es la propiedad deseada.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $prefix
 * @property string $token_hash
 * @property array<int, string> $scopes
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property-read Tenant|null $tenant
 */
class ApiKey extends Model
{
    use HasUuids;

    protected $connection = 'system';

    /** Identifica visiblemente al entorno sin revelar nada del secreto. */
    public const TOKEN_PREFIX = 'd4s_';

    /** 32 bytes = 256 bits de entropia. */
    private const SECRET_BYTES = 32;

    protected $fillable = [
        'tenant_id',
        'name',
        'prefix',
        'token_hash',
        'scopes',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Genera un secreto nuevo y devuelve la clave persistida junto al valor
     * en claro, que el llamador debe mostrar y despues olvidar.
     *
     * @param  array<int, string>  $scopes
     * @return array{0: self, 1: string}
     */
    public static function issue(Tenant $tenant, string $name, array $scopes = ['ingest:write', 'query:read']): array
    {
        $secret = self::TOKEN_PREFIX.bin2hex(random_bytes(self::SECRET_BYTES));

        $key = self::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'prefix' => substr($secret, 0, 16),
            'token_hash' => self::hash($secret),
            'scopes' => $scopes,
        ]);

        return [$key, $secret];
    }

    /**
     * SHA-256 y no bcrypt. El razonamiento completo esta en la migracion de
     * la tabla: contra un secreto aleatorio de 256 bits el costo de bcrypt
     * no compra seguridad, y ademas impediria autenticar con un indice.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], strict: true);
    }
}
