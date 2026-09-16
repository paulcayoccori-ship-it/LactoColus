<?php

namespace Database\Factories\Infrastructure\Penalizaciones;

use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Penalizaciones\SancionCalidad;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SancionCalidadFactory extends Factory
{
    protected $model = SancionCalidad::class;

    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'analisis_id' => AnalisisCalidad::factory(), 'productor_id' => fn (array $a) => AnalisisCalidad::findOrFail($a['analisis_id'])->productor_id, 'tipo' => 'amonestacion', 'estado' => 'pendiente_configuracion', 'numero_falta' => 1, 'agua_anadida' => '1', 'regla_aplicada' => [], 'fuente_snapshot' => [], 'huella' => hash('sha256', (string) Str::uuid())];
    }
}
