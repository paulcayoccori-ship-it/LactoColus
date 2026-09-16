<?php

namespace App\Application\Comunicados;

use App\Application\Operacion\AuditarOperacion;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Comunicados\Comunicado;
use App\Infrastructure\Comunicados\CuentaProductor;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class GestionarComunicados
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit) {}

    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-comunicados');
    }

    public function save(int $actor, array $input, ?string $uuid = null): Comunicado
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['uuid' => ['required', 'uuid'], 'titulo' => ['required', 'string', 'max:200'], 'contenido' => ['required', 'string', 'max:20000'], 'tipo' => ['required', 'in:general,urgente,capacitacion'], 'audiencia' => ['required', 'in:todos_productores,ruta,seleccionados,roles'], 'ruta_id' => ['nullable', 'integer'], 'productores' => ['sometimes', 'array', 'max:5000'], 'productores.*' => ['integer', 'distinct'], 'roles' => ['sometimes', 'array'], 'roles.*' => ['string', 'distinct', Rule::in(Role::where('guard_name', 'web')->where('name', '!=', 'productor')->pluck('name')->all())], 'publicar_at' => ['required', 'date'], 'vence_at' => ['nullable', 'date', 'after:publicar_at']], ['required' => 'El campo :attribute es obligatorio.', 'uuid' => 'UUID inválido.', 'string' => 'El campo :attribute debe ser texto.', 'max' => 'El campo :attribute supera la capacidad permitida.', 'in' => 'Selecciona una opción válida para :attribute.', 'integer' => 'Identificador no válido.', 'distinct' => 'No repitas destinatarios.', 'date' => 'Fecha inválida.', 'after' => 'El vencimiento debe ser posterior a la publicación.', 'array' => 'Selecciona una lista válida.'])->validate();
        $reason = $uuid ? $this->audit->reason((string) ($input['motivo'] ?? '')) : 'Creación de comunicado';

        return $this->users->underAdminLock(function () use ($actor, $data, $uuid, $reason): Comunicado {
            $this->authorize($actor);
            $record = $uuid ? Comunicado::where('uuid', $uuid)->lockForUpdate()->firstOrFail() : Comunicado::where('uuid', $data['uuid'])->first();
            if (! $uuid && $record) {
                return $record;
            }
            if ($record && $record->estado !== 'borrador') {
                throw ValidationException::withMessages(['estado' => 'Solo se corrigen borradores. Anula el publicado y crea otro para comunicar una rectificación.']);
            }
            $before = $record?->toArray() ?? [];
            $ids = [];
            $roles = [];
            $route = null;
            if ($data['audiencia'] === 'roles') {
                $roles = $data['roles'] ?? [];
                if (! $roles) {
                    throw ValidationException::withMessages(['roles' => 'Selecciona al menos un rol interno.']);
                }
            } else {
                $query = Productor::where('estado', true);
                if ($data['audiencia'] === 'ruta') {
                    $route = RutaAcopio::whereKey($data['ruta_id'] ?? null)->where('estado', true)->first();
                    if (! $route) {
                        throw ValidationException::withMessages(['ruta_id' => 'Selecciona una ruta activa.']);
                    } $query->whereIn('id', DB::table('ruta_productor')->where('ruta_id', $route->id)->select('productor_id'));
                }
                if ($data['audiencia'] === 'seleccionados') {
                    $requested = $data['productores'] ?? [];
                    if (! $requested) {
                        throw ValidationException::withMessages(['productores' => 'Selecciona productores activos.']);
                    } $query->whereIn('id', $requested);
                }
                $ids = $query->orderBy('id')->lockForUpdate()->pluck('id')->all();
                if (! $ids || ($data['audiencia'] === 'seleccionados' && count($ids) !== count($data['productores']))) {
                    throw ValidationException::withMessages(['productores' => 'La audiencia debe contener productores activos y no eliminados.']);
                }
            }
            if ($record) {
                $old = DB::table('comunicado_productor')->where('comunicado_id', $record->id)->pluck('productor_id')->all();
                sort($old);
                if ($old !== $ids || $record->audiencia !== $data['audiencia'] || $record->roles_destino !== $roles || $record->ruta_id !== $route?->id) {
                    throw ValidationException::withMessages(['audiencia' => 'La audiencia histórica no se reemplaza. Anula este borrador y crea otro.']);
                }
            }
            $record ??= new Comunicado(['uuid' => $data['uuid'], 'autor_id' => $actor, 'estado' => 'borrador']);
            $record->fill(['titulo' => $data['titulo'], 'contenido' => $data['contenido'], 'tipo' => $data['tipo'], 'audiencia' => $data['audiencia'], 'ruta_id' => $route?->id, 'roles_destino' => $roles, 'publicar_at' => Carbon::parse($data['publicar_at'])->setTimezone(config('app.timezone')), 'vence_at' => empty($data['vence_at']) ? null : Carbon::parse($data['vence_at'])->setTimezone(config('app.timezone'))])->save();
            if (! $uuid) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table('comunicado_productor')->insert(array_map(fn ($id) => ['comunicado_id' => $record->id, 'productor_id' => $id], $chunk));
                }
            }
            $this->audit->record('comunicados', $record->uuid, $uuid ? 'correccion' : 'creacion', $actor, $before, $record->toArray() + ['productores' => $ids], $reason);

            return $record;
        });
    }

    public function publish(int $actor, string $uuid): Comunicado
    {
        $this->authorize($actor);

        return $this->users->underAdminLock(function () use ($actor, $uuid): Comunicado {
            $this->authorize($actor);
            $record = Comunicado::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if (in_array($record->estado, ['programado', 'publicado'], true)) {
                return $record;
            } if ($record->estado !== 'borrador' || $record->vence_at?->isPast()) {
                throw ValidationException::withMessages(['estado' => 'No se puede publicar un comunicado anulado o vencido.']);
            } $before = $record->toArray();
            $record->update(['estado' => $record->publicar_at->isFuture() ? 'programado' : 'publicado']);
            $this->audit->record('comunicados', $uuid, 'publicacion', $actor, $before, $record->toArray(), 'Publicación autorizada');

            return $record;
        });
    }

    public function annul(int $actor, string $uuid, string $reason): Comunicado
    {
        $this->authorize($actor);
        $reason = $this->audit->reason($reason);

        return $this->users->underAdminLock(function () use ($actor, $uuid, $reason): Comunicado {
            $this->authorize($actor);
            $record = Comunicado::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($record->estado === 'anulado') {
                return $record;
            } $before = $record->toArray();
            $record->update(['estado' => 'anulado']);
            $this->audit->record('comunicados', $uuid, 'anulacion', $actor, $before, $record->toArray(), $reason);

            return $record;
        });
    }

    public function read(int $actor, string $uuid): Comunicado
    {
        return $this->users->underAdminLock(function () use ($actor, $uuid): Comunicado {
            $record = app(ConsultarComunicados::class)->find($actor, $uuid);
            DB::table('lecturas_comunicado')->insertOrIgnore(['comunicado_id' => $record->id, 'usuario_id' => $actor, 'leida_at' => now()]);

            return $record;
        });
    }

    public function link(int $actor, array $input): CuentaProductor
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['usuario_id' => ['required', 'integer'], 'productor_id' => ['required', 'integer'], 'activa' => ['required', 'boolean'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'Completa :attribute.', 'integer' => 'Identificador inválido.', 'boolean' => 'Estado inválido.', 'max' => 'Motivo demasiado largo.'])->validate();
        $reason = $this->audit->reason($data['motivo']);

        return $this->users->underAdminLock(function () use ($actor, $data, $reason): CuentaProductor {
            $this->authorize($actor);
            $user = User::whereKey($data['usuario_id'])->where('active', true)->role('productor', 'web')->lockForUpdate()->first();
            $producer = Productor::whereKey($data['productor_id'])->where('estado', true)->lockForUpdate()->first();
            if (! $user || ! $producer) {
                throw ValidationException::withMessages(['cuenta' => 'Selecciona un usuario activo con rol productor y un productor activo.']);
            } $link = CuentaProductor::where('usuario_id', $user->id)->orWhere('productor_id', $producer->id)->lockForUpdate()->first();
            if ($link && ($link->usuario_id !== $user->id || $link->productor_id !== $producer->id)) {
                throw ValidationException::withMessages(['cuenta' => 'La identidad ya tiene otra vinculación. No se reasigna silenciosamente.']);
            } $before = $link?->toArray() ?? [];
            $link ??= new CuentaProductor(['usuario_id' => $user->id, 'productor_id' => $producer->id]);
            $link->activa = $data['activa'];
            $link->save();
            $this->audit->record('comunicados', 'cuenta:'.$link->id, 'vinculacion', $actor, $before, $link->toArray(), $reason);

            return $link;
        });
    }

    public function updateScheduled(): int
    {
        return $this->users->underAdminLock(function (): int {
            $count = 0;
            Comunicado::whereIn('estado', ['programado', 'publicado'])->where(fn ($q) => $q->where('publicar_at', '<=', now())->orWhere('vence_at', '<=', now()))->orderBy('id')->lockForUpdate()->chunkById(100, function ($records) use (&$count): void {
                foreach ($records as $record) {
                    $state = $record->visibleState();
                    if ($state !== $record->estado) {
                        $before = $record->toArray();
                        $record->update(['estado' => $state]);
                        $this->audit->record('comunicados', $record->uuid, 'programacion_automatica', $record->autor_id, $before, $record->toArray(), 'Ejecución automática de la publicación previamente autorizada');
                        $count++;
                    }
                }
            });

            return $count;
        });
    }
}
