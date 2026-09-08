<?php

declare(strict_types=1);

namespace App\Infrastructure\Usuarios;

use App\Domain\Usuarios\UsuarioRepository;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class EloquentUsuarioRepository implements UsuarioRepository
{
    public function paginate(string $search, string $role, ?bool $active): LengthAwarePaginator
    {
        return User::query()->select(['id', 'name', 'email', 'active'])->with('roles:id,name,guard_name')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($role !== '', fn (Builder $query) => $query->whereHas('roles', fn (Builder $query) => $query->where('name', $role)->where('guard_name', 'web')))
            ->when($active !== null, fn (Builder $query) => $query->where('active', $active))
            ->orderByDesc('id')->paginate(15)->through(fn (User $user): array => $this->attributes($user));
    }

    public function roles(): array
    {
        return Role::query()->where('guard_name', 'web')->orderBy('name')->get(['name'])->toArray();
    }

    public function find(int $id): array
    {
        return $this->attributes(User::query()->lockForUpdate()->findOrFail($id));
    }

    public function underAdminLock(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            /** A write also serializes SQLite transactions, where FOR UPDATE is ignored. */
            Role::query()->where('name', 'administrador')->where('guard_name', 'web')->update(['name' => 'administrador']);
            Role::query()->where('name', 'administrador')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();

            return $operation();
        }, 5);
    }

    public function activeAdminIds(): array
    {
        return User::query()->where('active', true)->role('administrador', 'web')->orderBy('id')->lockForUpdate()->pluck('id')->all();
    }

    public function save(?int $id, #[\SensitiveParameter] array $data): void
    {
        $user = $id === null ? new User : User::query()->lockForUpdate()->findOrFail($id);
        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        if ($user->exists && $user->active && ! $data['active']) {
            $user->auth_version++;
            $user->remember_token = Str::random(60);
            $user->tokens()->delete();
        }
        $user->active = $data['active'];
        try {
            $user->save();
            $user->syncRoles($data['roles']);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => 'El correo electrónico ya está registrado.']);
        }
    }

    /** @return array{id:int, name:string, email:string, active:bool, roles:array} */
    private function attributes(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => $user->active,
            'roles' => $user->roles->where('guard_name', 'web')->pluck('name')->all()];
    }
}
