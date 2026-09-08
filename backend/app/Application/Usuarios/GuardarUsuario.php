<?php

declare(strict_types=1);

namespace App\Application\Usuarios;

use App\Domain\Usuarios\UsuarioRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GuardarUsuario
{
    public function __construct(private UsuarioRepository $repository) {}

    public function handle(int $actorId, ?int $id, #[\SensitiveParameter] array $input): void
    {
        Gate::authorize('administrar-usuarios');
        $this->repository->underAdminLock(function () use ($actorId, $id, $input): void {
            $actor = $this->repository->find($actorId);
            abort_unless($actor['active'] && in_array('administrador', $actor['roles'], true), 403);
            $existing = $id === null ? null : $this->repository->find($id);
            $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
            $input['email'] = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : ($input['email'] ?? null);
            $data = Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
                'password' => [$id === null ? 'required' : 'nullable', 'string', 'min:8', 'max:72', 'not_regex:/\\x00/', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('La contraseña no puede superar 72 bytes.');
                    }
                }],
                'active' => ['required', 'boolean'],
                'roles' => ['required', 'array', 'min:1'],
                'roles.*' => ['required', 'string', 'distinct', Rule::in(array_column($this->repository->roles(), 'name'))],
            ], [
                'required' => 'El campo :attribute es obligatorio.',
                'string' => 'El campo :attribute debe ser texto.',
                'max' => 'El campo :attribute supera la longitud permitida.',
                'email.email' => 'Ingresa un correo electrónico válido.',
                'email.unique' => 'El correo electrónico ya está registrado.',
                'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
                'password.not_regex' => 'La contraseña contiene caracteres no permitidos.',
                'password.confirmed' => 'La confirmación de contraseña no coincide.',
                'active.boolean' => 'Selecciona un estado válido.',
                'roles.array' => 'Selecciona los roles de la lista.',
                'roles.min' => 'Selecciona al menos un rol.',
                'roles.*.in' => 'Selecciona un rol existente.',
                'roles.*.distinct' => 'No repitas roles.',
            ], ['name' => 'nombre', 'email' => 'correo electrónico', 'password' => 'contraseña', 'active' => 'estado', 'roles' => 'roles'])->validate();

            $this->protectAdministrators($actorId, $existing, $data);
            $this->repository->save($id, $data);
        });
    }

    public function changeState(int $actorId, int $id, bool $active): void
    {
        Gate::authorize('administrar-usuarios');
        $this->repository->underAdminLock(function () use ($actorId, $id, $active): void {
            $data = $this->repository->find($id);
            $data['active'] = $active;
            $this->handle($actorId, $id, $data);
        });
    }

    private function protectAdministrators(int $actorId, ?array $existing, array $data): void
    {
        if ($existing === null) {
            return;
        }
        if ($existing['id'] === $actorId && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'No puedes desactivar tu propia cuenta.']);
        }
        if ($existing['active'] && in_array('administrador', $existing['roles'], true)
            && (! $data['active'] || ! in_array('administrador', $data['roles'], true))
            && count(array_diff($this->repository->activeAdminIds(), [$existing['id']])) === 0) {
            throw ValidationException::withMessages(['roles' => 'Debe permanecer al menos un administrador activo.']);
        }
    }
}
