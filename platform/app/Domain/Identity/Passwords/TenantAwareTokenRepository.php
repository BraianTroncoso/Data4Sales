<?php

declare(strict_types=1);

namespace App\Domain\Identity\Passwords;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Repositorio de tokens de recuperacion acotado al tenant.
 *
 * El de Laravel busca, borra y valida por `email` a secas. Eso alcanza cuando
 * el email identifica a una persona en todo el sistema; aca no lo hace: el
 * unico de `users` es (tenant_id, email) por decision explicita (D-08).
 *
 * Sin este acotamiento, con el mismo email en dos organizaciones:
 *
 *   - pedir el enlace en una borra el token vigente de la otra;
 *   - y un token emitido para el usuario de un tenant valida contra el usuario
 *     del otro, o sea que un reset legitimo puede terminar cambiando la
 *     contrasena de otra persona.
 *
 * Cada consulta se filtra por las DOS columnas, que son ademas la clave
 * primaria de la tabla desde la migracion que agrego `tenant_id`.
 */
class TenantAwareTokenRepository extends DatabaseTokenRepository
{
    public function create(CanResetPassword $user)
    {
        $this->deleteExisting($user);

        $token = $this->createNewToken();

        $this->getTable()->insert(
            $this->getPayload($user->getEmailForPasswordReset(), $token)
            + ['tenant_id' => $this->tenantId($user)]
        );

        return $token;
    }

    public function exists(CanResetPassword $user, #[\SensitiveParameter] $token)
    {
        $record = (array) $this->scoped($user)->first();

        return $record !== []
            && ! $this->tokenExpired($record['created_at'])
            && $this->hasher->check($token, $record['token']);
    }

    public function recentlyCreatedToken(CanResetPassword $user)
    {
        $record = (array) $this->scoped($user)->first();

        return $record !== [] && $this->tokenRecentlyCreated($record['created_at']);
    }

    protected function deleteExisting(CanResetPassword $user)
    {
        return $this->scoped($user)->delete();
    }

    private function scoped(CanResetPassword $user): Builder
    {
        return $this->getTable()
            ->where('tenant_id', $this->tenantId($user))
            ->where('email', $user->getEmailForPasswordReset());
    }

    /**
     * Falla ruidosamente en vez de degradar a "sin tenant".
     *
     * Un null aca volveria a la semantica vieja sin avisar, que es exactamente
     * el fallo que esta clase existe para impedir.
     */
    private function tenantId(CanResetPassword $user): string
    {
        if (! $user instanceof User) {
            throw new RuntimeException('La recuperación de contraseña sólo aplica a usuarios del dashboard.');
        }

        return $user->getAttribute('tenant_id');
    }
}
