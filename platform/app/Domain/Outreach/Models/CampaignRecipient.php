<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use App\Domain\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un cliente dentro de una audiencia congelada.
 *
 * `email_hash` se guarda y no se recalcula al enviar porque es la clave de
 * deduplicacion: dos filas de `customers` pueden compartir direccion (el
 * email es dato de contacto, no identidad — ver D-08), y sin esto la misma
 * persona recibiria dos copias del mismo correo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $customer_id
 * @property string $email_hash
 * @property Carbon $entered_at
 * @property string|null $exit_reason
 * @property-read Customer|null $customer
 * @property-read CampaignRun|null $run
 */
class CampaignRecipient extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'run_id', 'customer_id', 'email_hash', 'entered_at', 'exit_reason'];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CampaignRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'run_id');
    }
}
