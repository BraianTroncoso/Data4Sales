<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cada llamada al proveedor, con como termino.
 *
 * Es una tabla aparte y no columnas en el intento porque un intento puede
 * tener varios: reintentos tras un error transitorio. Colapsarlo perderia
 * exactamente la informacion que hace falta para explicar por que algo tardo.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $intent_id
 * @property int $attempt_no
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $outcome
 * @property string|null $provider_status
 * @property string|null $error_class
 * @property string|null $error_message
 */
class SendAttempt extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'intent_id', 'attempt_no', 'started_at', 'finished_at',
        'outcome', 'provider_status', 'error_class', 'error_message',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'attempt_no' => 'integer'];
    }
}
