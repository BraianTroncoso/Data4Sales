<?php

declare(strict_types=1);

namespace App\Http\Dashboard\Controllers;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\TenantContext;
use App\Http\Dashboard\Requests\StoreUserRequest;
use App\Http\Dashboard\Requests\UpdateUserRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gestión de usuarios de la plataforma. Sólo administradores.
 *
 * Toda consulta está acotada al tenant de la sesión con un `where`
 * explícito. Los usuarios viven en el plano de control, que no tiene
 * Row-Level Security porque debe ser consultable antes de conocer el tenant
 * (ver D-03) — así que acá el aislamiento SÍ depende de la aplicación, y por
 * eso el filtro está en cada consulta y hay tests que lo prueban.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->context->currentOrFail();

        $users = User::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard/Users/Index', [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'currency' => $tenant->reporting_currency,
                'timezone' => $tenant->timezone,
            ],
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                // Email completo: son empleados del tenant, no compradores.
                // La distinción respecto de la pantalla de clientes es
                // deliberada, no un descuido.
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'is_active' => $user->is_active,
                'created_at' => $user->created_at?->toIso8601String(),
                // Deshabilita los controles que dejarían al administrador
                // fuera de su propia organización.
                'is_self' => $user->id === $request->user()->id,
            ])->all(),
            'roleOptions' => array_map(static fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => match ($role) {
                    UserRole::Analyst => 'Sólo estadísticas agregadas. Sin acceso a datos personales de compradores.',
                    UserRole::Support => 'Sólo datos de clientes, para atender casos. Sin acceso a estadísticas.',
                    UserRole::Admin => 'Todo lo anterior, más la gestión de usuarios de esta organización.',
                },
            ], UserRole::cases()),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::create([
            // Del contexto, nunca del request.
            'tenant_id' => $this->context->currentOrFail()->id,
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->lower()->toString(),
            'password' => $request->string('password')->toString(),
            'role' => $request->enum('role', UserRole::class),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return back()->with('status', 'Usuario creado.');
    }

    public function update(UpdateUserRequest $request, string $user): RedirectResponse
    {
        $model = User::query()
            ->where('tenant_id', $this->context->currentOrFail()->id)
            ->find($user);

        if ($model === null) {
            throw new NotFoundHttpException('El usuario no existe.');
        }

        // Un administrador no puede cambiarse el rol ni desactivarse a sí
        // mismo. Sin esta regla, el único administrador de un tenant puede
        // dejarse afuera y hace falta intervención manual en la base para
        // devolverle el acceso.
        if ($model->id === $request->user()->id) {
            return back()->withErrors([
                'role' => 'No podés cambiar tu propio rol ni desactivarte.',
            ]);
        }

        $model->fill($request->safe()->only(['role', 'is_active']))->save();

        return back()->with('status', 'Usuario actualizado.');
    }
}
