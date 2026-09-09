<?php

declare(strict_types=1);

namespace App\Application\Rutas;

use App\Domain\Rutas\RutaRepository;
use App\Domain\Usuarios\UsuarioRepository;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GestionarRutas
{
    public function __construct(private RutaRepository $repository, private UsuarioRepository $users) {}

    private function write(Closure $operation): mixed
    {
        Gate::authorize('administrar-rutas');
        try {
            return $this->users->underAdminLock(function () use ($operation): mixed {
                Gate::authorize('administrar-rutas');

                return $operation();
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['conflicto' => 'Otro cambio entró en conflicto. Revisa el código, las asignaciones y el orden antes de reintentar.']);
        }
    }

    public function save(?int $id, array $input): int
    {
        return $this->write(function () use ($id, $input): int {
            if ($id !== null) {
                $this->repository->find($id, true);
            }
            foreach (['codigo', 'nombre', 'descripcion'] as $field) {
                if (is_string($input[$field] ?? null)) {
                    $input[$field] = trim($input[$field]);
                }
            }
            $data = Validator::make($input, ['codigo' => ['required', 'string', 'max:50', Rule::unique('rutas_acopio', 'codigo')->ignore($id)], 'nombre' => ['required', 'string', 'max:150'], 'descripcion' => ['nullable', 'string', 'max:5000'], 'estado' => ['required', 'boolean']],
                ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.', 'max' => 'El campo :attribute supera la longitud permitida.', 'unique' => 'El código ya está registrado.', 'boolean' => 'Selecciona un estado válido.'], ['codigo' => 'código', 'nombre' => 'nombre', 'descripcion' => 'descripción', 'estado' => 'estado'])->validate();

            return $this->repository->save($id, $data);
        });
    }

    public function changeState(int $id, bool $estado): void
    {
        $this->write(function () use ($id, $estado): void {
            $this->repository->find($id, true);
            $this->repository->save($id, ['estado' => $estado]);
        });
    }

    public function collector(int $id, mixed $collector): void
    {
        $this->write(function () use ($id, $collector): void {
            $this->repository->find($id, true);
            $data = Validator::make(['recolector_id' => $collector], ['recolector_id' => [$collector === null ? 'nullable' : 'required', 'integer', 'min:1']], ['required' => 'Selecciona un recolector.', 'integer' => 'Selecciona un recolector válido.', 'min' => 'Selecciona un recolector válido.'])->validate();
            if ($data['recolector_id'] !== null && ! ($this->repository->collector((int) $data['recolector_id'])['disponible'] ?? false)) {
                throw ValidationException::withMessages(['recolector_id' => 'Solo puedes asignar usuarios activos con el rol recolector.']);
            }
            $this->repository->save($id, $data);
        });
    }

    public function attach(int $id, mixed $producer): void
    {
        $this->write(function () use ($id, $producer): void {
            $ruta = $this->repository->find($id, true);
            Validator::make(['productor_id' => $producer], ['productor_id' => ['required', 'integer', 'min:1']], ['required' => 'Selecciona un productor.', 'integer' => 'Selecciona un productor válido.', 'min' => 'Selecciona un productor válido.'])->validate();
            $producer = (int) $producer;
            if (! ($this->repository->producer($producer)['disponible'] ?? false)) {
                throw ValidationException::withMessages(['productor_id' => 'Solo puedes incorporar productores activos y no eliminados.']);
            }
            $assignment = $this->repository->assignment($producer);
            if ($assignment) {
                $message = $assignment['ruta_id'] === $id ? 'El productor ya pertenece a esta ruta.' : 'El productor pertenece a la ruta '.$this->repository->find($assignment['ruta_id'])['codigo'].'. Retíralo de esa ruta antes de incorporarlo.';
                throw ValidationException::withMessages(['productor_id' => $message]);
            }
            $this->repository->attach($id, $producer, count($ruta['productores']) + 1);
        });
    }

    public function detach(int $id, int $producer): void
    {
        $this->write(function () use ($id, $producer): void {
            $ruta = $this->repository->find($id, true);
            abort_unless(in_array($producer, array_column($ruta['productores'], 'id'), true), 404);
            $this->repository->detach($id, $producer);
            $this->repository->reorder($id, array_values(array_diff(array_column($ruta['productores'], 'id'), [$producer])));
        });
    }

    public function move(int $id, int $producer, int $direction): void
    {
        $this->write(function () use ($id, $producer, $direction): void {
            abort_unless(in_array($direction, [-1, 1], true), 422, 'Dirección de visita inválida.');
            $ids = array_column($this->repository->find($id, true)['productores'], 'id');
            $index = array_search($producer, $ids, true);
            abort_if($index === false, 404);
            $target = $index + $direction;
            if (isset($ids[$target])) {
                [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
                $this->repository->reorder($id, $ids);
            }
        });
    }
}
