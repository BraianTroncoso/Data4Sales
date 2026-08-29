<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Http;

use App\Domain\Outreach\Models\Campaign;
use App\Domain\Outreach\Models\Suppression;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Baja de suscripcion.
 *
 * Publica y firmada. Publica porque el destinatario es un comprador, no un
 * usuario de la plataforma: exigirle una cuenta para dejar de recibir correos
 * es la forma mas rapida de que marque el mensaje como spam. Firmada porque
 * sin firma cualquiera podria dar de baja a cualquiera conociendo el hash.
 *
 * La baja se guarda por HASH del email, no por cliente. Vale para la persona,
 * no para la fila: si el retailer reenvia manana el mismo comprador con otro
 * identificador de origen, la supresion tiene que seguir valiendo. Atarla al
 * cliente haria que un reenvio del padron la borre sin que nadie lo note.
 *
 * El GET confirma y el POST ejecuta. No es formalismo: los clientes de correo
 * y los escaneadores de seguridad visitan los enlaces de un mensaje
 * automaticamente, y con la baja en el GET media lista se daria de baja sola.
 * La cabecera `List-Unsubscribe-Post` apunta al POST, que es lo que permite
 * la baja de un clic desde Gmail sin ese riesgo.
 */
class UnsubscribeController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request, string $tenant, string $campaign, string $hash): Response
    {
        [$tenantModel, $campaignModel] = $this->resolve($tenant, $campaign);

        $yaDeBaja = $this->context->run(
            $tenantModel,
            fn () => Suppression::query()->where('email_hash', $hash)->exists(),
        );

        return response()->view('outreach.unsubscribe', [
            'tenantName' => $tenantModel->name,
            'campaignName' => $campaignModel->name,
            'alreadyUnsubscribed' => $yaDeBaja,
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, string $tenant, string $campaign, string $hash): Response
    {
        [$tenantModel] = $this->resolve($tenant, $campaign);

        $this->context->run($tenantModel, function () use ($hash, $tenantModel): void {
            // Idempotente: darse de baja dos veces no es un error, y el
            // cliente de correo puede reintentar el POST.
            DB::connection($this->context->connectionName())->statement(
                <<<'SQL'
                INSERT INTO suppressions (tenant_id, email_hash, reason, scope, source, created_at)
                VALUES (?, ?, 'unsubscribed', 'tenant', 'link', NOW())
                ON CONFLICT (tenant_id, email_hash, scope) DO NOTHING
                SQL,
                [$tenantModel->id, $hash],
            );
        });

        return response()->view('outreach.unsubscribe', [
            'tenantName' => $tenantModel->name,
            'campaignName' => null,
            'alreadyUnsubscribed' => true,
            'action' => null,
        ]);
    }

    /**
     * @return array{Tenant, Campaign}
     */
    private function resolve(string $tenant, string $campaign): array
    {
        $tenantModel = Tenant::query()->where('slug', $tenant)->where('is_active', true)->first();

        if ($tenantModel === null) {
            throw new NotFoundHttpException;
        }

        $campaignModel = $this->context->run(
            $tenantModel,
            fn () => Campaign::query()->where('slug', $campaign)->first(),
        );

        if ($campaignModel === null) {
            throw new NotFoundHttpException;
        }

        return [$tenantModel, $campaignModel];
    }
}
