<?php

namespace Database\Factories\Infrastructure\Traslados;

use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Infrastructure\Traslados\SolicitudTraslado;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SolicitudTrasladoFactory extends Factory
{
    protected $model = SolicitudTraslado::class;

    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'productor_id' => Productor::factory(), 'ruta_actual_id' => RutaAcopio::factory(), 'ruta_solicitada_id' => RutaAcopio::factory(), 'solicitante_id' => User::factory(), 'solicitada_at' => now(), 'fecha_efectiva' => today()->addDays(3), 'motivo' => 'Traslado solicitado', 'estado' => 'pendiente', 'regla_aplicada' => ['clave' => 'traslados', 'version' => 1, 'valores' => ['anticipacion_dias' => 3]]];
    }
}
