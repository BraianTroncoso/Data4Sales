<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Contenido inmutable y versionado.
 *
 * Una campana apunta a una VERSION. Sin eso, editar el texto cambiaria
 * retroactivamente lo que decimos que se envio, y "que recibio este cliente"
 * dejaria de tener respuesta.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_id
 * @property int $version
 * @property string $subject
 * @property string $body_text
 * @property string|null $body_html
 * @property array<int, string> $declared_variables
 * @property string $content_hash
 * @property Carbon|null $published_at
 * @property-read Template|null $template
 */
class TemplateVersion extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'template_id', 'version', 'subject',
        'body_text', 'body_html', 'declared_variables', 'content_hash', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'declared_variables' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
