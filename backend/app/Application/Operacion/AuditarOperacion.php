<?php

namespace App\Application\Operacion;

use App\Infrastructure\Operacion\AuditoriaOperativa;
use Illuminate\Support\Facades\Validator;

final class AuditarOperacion
{
    public function reason(string $reason): string
    {
        return Validator::make(['motivo' => trim($reason)], ['motivo' => ['required', 'string', 'max:2000']], ['required' => 'El motivo es obligatorio.', 'max' => 'El motivo no puede superar 2000 caracteres.'])->validate()['motivo'];
    }

    public function record(string $module, string $entity, string $action, int $actor, array $before, array $after, string $reason): void
    {
        AuditoriaOperativa::create(['modulo' => $module, 'entidad' => $entity, 'accion' => $action, 'usuario_id' => $actor, 'anteriores' => $before, 'nuevos' => $after, 'motivo' => $reason]);
    }
}
