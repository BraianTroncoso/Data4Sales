<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\IsolationStrategy;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un retailer de la plataforma.
 *
 * Vive en el plano de control, nunca en el plano de datos. Un tenant sabe
 * donde estan sus datos; sus datos no saben nada de el mas alla de la
 * columna tenant_id.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property IsolationStrategy $isolation_strategy
 * @property string|null $connection_name
 * @property string|null $database_name
 * @property string $timezone
 * @property string $reporting_currency
 * @property int $ingest_max_batch_size
 * @property bool $is_active
 * @property-read Collection<int, ApiKey> $apiKeys
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    protected $connection = 'system';

    protected $fillable = [
        'slug',
        'name',
        'isolation_strategy',
        'connection_name',
        'database_name',
        'timezone',
        'reporting_currency',
        'ingest_max_batch_size',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'isolation_strategy' => IsolationStrategy::class,
            'ingest_max_batch_size' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * El slug es siempre minusculas.
     *
     * El invariante vive en el modelo y no en cada lugar que lo escribe,
     * porque tambien lo usa el login: la pantalla normaliza a minusculas lo
     * que tipea el usuario, y si algun slug se guardara con mayusculas ese
     * tenant simplemente no podria entrar. Un bug asi solo aparece con el
     * tenant que lo tiene, y nadie lo relaciona con el login.
     */
    protected function slug(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value) => mb_strtolower(trim($value)),
        );
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isDedicated(): bool
    {
        return $this->isolation_strategy === IsolationStrategy::Dedicated;
    }

    /**
     * La clave de ruteo por el slug en vez del uuid es para las rutas del
     * dashboard y el login: el usuario escribe "andes", no un uuid.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
