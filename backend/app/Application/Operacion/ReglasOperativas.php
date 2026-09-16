<?php

namespace App\Application\Operacion;

use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Operacion\ReglaOperativa;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class ReglasOperativas
{
    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit) {}

    public function current(string $key): array
    {
        $rule = ReglaOperativa::where('clave', $key)->orderByDesc('version')->first();

        return $rule ? ['clave' => $key, 'version' => $rule->version, 'valores' => $rule->valores] : ['clave' => $key, 'version' => 0, 'valores' => config('operacion.'.$key, [])];
    }

    public function saveProduction(int $actorId, array $input, string $reason): array
    {
        Gate::forUser(User::findOrFail($actorId))->authorize('administrar-produccion');
        $reason = $this->audit->reason($reason);
        $values = Validator::make($input, ['minimo' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:1000'], 'maximo' => ['required', 'numeric', 'decimal:0,3', 'gte:minimo', 'max:1000']], ['required' => 'Completa ambos límites.', 'numeric' => 'Los límites deben ser numéricos.', 'decimal' => 'Usa hasta tres decimales.', 'min' => 'El límite no puede ser negativo.', 'max' => 'El límite supera 1000 moldes por 100 litros.', 'gte' => 'El máximo debe ser mayor o igual al mínimo.'])->validate();
        $values = array_map(fn ($v) => bcadd((string) $v, '0', 3), $values);

        return $this->users->underAdminLock(function () use ($actorId, $values, $reason): array {
            Gate::forUser(User::findOrFail($actorId))->authorize('administrar-produccion');
            $before = $this->current('rendimiento');
            ReglaOperativa::create(['clave' => 'rendimiento', 'version' => $before['version'] + 1, 'valores' => $values, 'autor_id' => $actorId, 'motivo' => $reason]);
            $after = $this->current('rendimiento');
            $this->audit->record('produccion', 'rendimiento', 'configuracion', $actorId, $before, $after, $reason);

            return $after;
        });
    }
}
