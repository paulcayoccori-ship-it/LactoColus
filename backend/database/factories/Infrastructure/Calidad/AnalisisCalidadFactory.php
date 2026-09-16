<?php

namespace Database\Factories\Infrastructure\Calidad;

use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AnalisisCalidadFactory extends Factory
{
    protected $model = AnalisisCalidad::class;

    public function definition(): array
    {
        return ['uuid_publico' => (string) Str::uuid(), 'uuid_externo' => (string) Str::uuid(), 'productor_id' => Productor::factory(), 'responsable_id' => User::factory(), 'muestra_at' => now(), 'sincronizada_at' => now(), 'fuente' => 'manual', 'grasa' => '3.5000', 'proteina' => '3.2000', 'lactosa' => '4.5000', 'densidad_medida' => '1.0300', 'temperatura' => '20.0000', 'densidad_corregida' => null, 'solidos_no_grasos' => '8.5000', 'ph' => '6.7000', 'acidez' => '0.1500', 'agua_anadida' => '0.0000', 'estado' => 'pendiente_revision', 'advertencias' => ['perfil' => 'Sin perfil'], 'limites_aplicados' => []];
    }
}
