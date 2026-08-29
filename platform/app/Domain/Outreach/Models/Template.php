<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $content_mode
 * @property-read Collection<int, TemplateVersion> $versions
 */
class Template extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'content_mode'];

    /** @return HasMany<TemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    /**
     * Contenido personalizado prohibe el envio en lote al proveedor.
     *
     * En un lote, el proveedor recibe una lista de destinos y una lista de
     * variables. Un desalineamiento de indices entre las dos ES la fuga de
     * datos, y no deja ningun rastro: cada persona recibe un correo
     * perfectamente formado con los datos de otra.
     */
    public function allowsBatchSending(): bool
    {
        return $this->content_mode === 'uniform';
    }
}
