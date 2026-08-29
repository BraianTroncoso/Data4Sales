<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una direccion a la que no hay que escribirle.
 *
 * Por HASH del email, no por cliente. Una baja vale para la PERSONA, no para
 * la fila: si alguien se da de baja y el retailer lo reenvia manana con otro
 * identificador de origen, la supresion tiene que seguir valiendo. Atarla al
 * cliente haria que un reenvio del padron la borre sin que nadie lo note.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $email_hash
 * @property string $reason
 * @property string $scope
 * @property string|null $source
 * @property Carbon $created_at
 * @property Carbon|null $expires_at
 */
class Suppression extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'email_hash', 'reason', 'scope', 'source', 'created_at', 'expires_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** Hash canonico de una direccion. */
    public static function hashFor(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
