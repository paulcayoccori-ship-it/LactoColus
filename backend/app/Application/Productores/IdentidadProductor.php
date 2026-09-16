<?php

namespace App\Application\Productores;

use App\Infrastructure\Comunicados\CuentaProductor;
use App\Infrastructure\Productores\Productor;
use App\Models\User;

final class IdentidadProductor
{
    public function id(int $actor): int
    {
        $user = User::findOrFail($actor);
        abort_unless($user->active && $user->hasRole('productor', 'web'), 403);
        $id = CuentaProductor::where('usuario_id', $actor)->where('activa', true)->whereIn('productor_id', Productor::where('estado', true)->select('id'))->value('productor_id');
        abort_unless($id, 403, 'La cuenta no tiene un productor activo vinculado.');

        return (int) $id;
    }
}
