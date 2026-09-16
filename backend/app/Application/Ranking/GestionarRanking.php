<?php

namespace App\Application\Ranking;

use App\Application\Operacion\AuditarOperacion;
use App\Application\Operacion\ReglasOperativas;
use App\Domain\Usuarios\UsuarioRepository;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Operacion\ReglaOperativa;
use App\Infrastructure\Ranking\CalculoRanking;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestionarRanking
{
    public const CAMPOS = ['solidos_totales' => ['Sólidos totales', '% m/m', '100', 40], 'densidad_corregida' => ['Densidad corregida', 'g/mL', '2', 30], 'acidez' => ['Acidez', '% ácido láctico', '100', 30]];

    public function __construct(private UsuarioRepository $users, private AuditarOperacion $audit, private ReglasOperativas $rules) {}

    public function authorize(int $actor): void
    {
        Gate::forUser(User::findOrFail($actor))->authorize('administrar-ranking');
    }

    public function configure(int $actor, array $input): array
    {
        $this->authorize($actor);
        $validation = ['activo' => ['required', 'boolean'], 'privacidad' => ['required', 'in:nombre_completo,nombre_abreviado,codigo'], 'agregacion' => ['required', 'in:media,minimo'], 'inicio_semana' => ['required', 'in:lunes,jueves'], 'motivo' => ['required', 'string', 'max:2000'], 'bandas' => ['required', 'array']];
        foreach (self::CAMPOS as $key => $field) {
            $validation['bandas.'.$key] = ['required', 'array', 'min:1', 'max:100'];
            foreach (['minimo', 'maximo'] as $bound) {
                $validation['bandas.'.$key.'.*.'.$bound] = ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:'.$field[2]];
            } $validation['bandas.'.$key.'.*.puntos'] = ['required', 'numeric', 'decimal:0,4', 'between:0,100'];
        }
        $data = Validator::make($input, $validation, ['required' => 'Completa :attribute.', 'in' => 'Selecciona una opción válida.', 'boolean' => 'Estado inválido.', 'numeric' => 'El valor debe ser numérico.', 'decimal' => 'Usa hasta cuatro decimales.', 'min' => 'El valor es inferior al mínimo.', 'max' => 'El valor supera la capacidad permitida.', 'between' => 'Los puntos deben estar entre 0 y 100.', 'array' => 'Configura una lista de bandas.'])->validate();
        $reason = $this->audit->reason($data['motivo']);
        unset($data['motivo']);
        $bands = [];
        foreach (self::CAMPOS as $key => $field) {
            $rows = $data['bandas'][$key];
            usort($rows, fn ($a, $b) => bccomp((string) $a['minimo'], (string) $b['minimo'], 4));
            $cursor = '0.0000';
            $bands[$key] = [];
            foreach ($rows as $row) {
                $min = bcadd((string) $row['minimo'], '0', 4);
                $max = bcadd((string) $row['maximo'], '0', 4);
                if (bccomp($cursor, $min, 4) !== 0 || bccomp($max, $min, 4) <= 0) {
                    throw ValidationException::withMessages(['bandas.'.$key => 'Las bandas deben cubrir todo el dominio desde cero, sin huecos ni solapamientos.']);
                } $bands[$key][] = ['minimo' => $min, 'maximo' => $max, 'puntos' => bcadd((string) $row['puntos'], '0', 4)];
                $cursor = $max;
            } if (bccomp($cursor, $field[2], 4) !== 0) {
                throw ValidationException::withMessages(['bandas.'.$key => 'La última banda debe terminar en '.$field[2].' '.$field[1].'.']);
            }
        }
        $data['bandas'] = $bands;
        $data['pesos'] = ['solidos_totales' => 40, 'densidad_corregida' => 30, 'acidez' => 30];
        $data['unidades'] = array_map(fn ($f) => $f[1], self::CAMPOS);
        $data['algoritmo'] = 'bandas_ponderadas_v1';

        return $this->users->underAdminLock(function () use ($actor, $data, $reason): array {
            $this->authorize($actor);
            $before = $this->rules->current('ranking');
            ReglaOperativa::create(['clave' => 'ranking', 'version' => $before['version'] + 1, 'valores' => $data, 'autor_id' => $actor, 'motivo' => $reason]);
            $after = $this->rules->current('ranking');
            $this->audit->record('ranking', 'reglas', 'configuracion', $actor, $before, $after, $reason);

            return $after;
        });
    }

    public function period(string $type, string $date, array $rule): array
    {
        $d = Carbon::parse($date)->startOfDay();
        $start = match ($type) {
            'diario' => $d,'semanal' => $d->startOfWeek($rule['inicio_semana'] === 'jueves' ? Carbon::THURSDAY : Carbon::MONDAY),'mensual' => $d->startOfMonth(),default => abort(422)
        };
        $end = match ($type) {
            'diario' => $start->copy(),'semanal' => $start->copy()->addDays(6),'mensual' => $start->copy()->endOfMonth()
        };

        return [$start->toDateString(), $end->toDateString()];
    }

    public function calculate(int $actor, array $input): CalculoRanking
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['tipo' => ['required', 'in:diario,semanal,mensual'], 'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'ruta_id' => ['nullable', 'integer'], 'motivo' => ['required', 'string', 'max:2000']], ['required' => 'Completa :attribute.', 'in' => 'Periodo inválido.', 'date_format' => 'Usa una fecha AAAA-MM-DD.', 'before_or_equal' => 'No calcules periodos futuros.', 'integer' => 'Ruta inválida.', 'max' => 'Motivo demasiado largo.'])->validate();
        $reason = $this->audit->reason($data['motivo']);

        return $this->users->underAdminLock(function () use ($actor, $data, $reason): CalculoRanking {
            $this->authorize($actor);
            $rule = $this->rules->current('ranking');
            if (! ($rule['valores']['activo'] ?? false) || empty($rule['valores']['bandas'])) {
                throw ValidationException::withMessages(['reglas' => 'Configura y activa criterios completos de puntuación antes de calcular.']);
            }
            [$start,$end] = $this->period($data['tipo'], $data['fecha'], $rule['valores']);
            $route = empty($data['ruta_id']) ? null : RutaAcopio::findOrFail($data['ruta_id']);
            $analyses = AnalisisCalidad::with(['productor', 'ruta'])->where('estado', '!=', 'anulado')->whereDate('muestra_at', '>=', $start)->whereDate('muestra_at', '<=', $end)->when($route, fn ($q) => $q->whereIn('productor_id', AnalisisCalidad::where('estado', '!=', 'anulado')->whereDate('muestra_at', '>=', $start)->whereDate('muestra_at', '<=', $end)->where('ruta_id', $route->id)->select('productor_id')))->orderBy('id')->lockForUpdate()->get();
            $sources = $analyses->map(fn ($a) => $a->only(['id', 'uuid_publico', 'productor_id', 'ruta_id', 'muestra_at', 'estado', 'solidos_totales', 'densidad_corregida', 'acidez', 'agua_anadida', 'updated_at']))->all();
            $fingerprint = hash('sha256', json_encode([$data['tipo'], $start, $end, $route?->id, $rule, $sources], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            CalculoRanking::where('tipo', $data['tipo'])->whereDate('desde', $start)->whereDate('hasta', $end)->where('ruta_id', $route?->id)->where('vigente', true)->update(['vigente' => false]);
            if ($existing = CalculoRanking::where('huella', $fingerprint)->first()) {
                $existing->update(['vigente' => true]);

                return $existing;
            }
            $run = CalculoRanking::create(['uuid' => (string) Str::uuid(), 'huella' => $fingerprint, 'tipo' => $data['tipo'], 'desde' => $start, 'hasta' => $end, 'ruta_id' => $route?->id, 'regla_aplicada' => $rule, 'algoritmo' => 'bandas_ponderadas_v1', 'usuario_id' => $actor, 'vigente' => true]);
            foreach ($sources as $source) {
                DB::table('fuentes_ranking')->insert(['calculo_id' => $run->id, 'analisis_id' => $source['id'], 'snapshot' => json_encode($source, JSON_THROW_ON_ERROR)]);
            }
            $rows = [];
            foreach ($analyses->groupBy('productor_id') as $producerId => $group) {
                $producer = $group->first()->productor;
                $water = $group->contains(fn ($a) => $a->agua_anadida !== null && bccomp($a->agua_anadida, '0', 4) > 0);
                $scores = [];
                $missing = [];
                $evaluations = [];
                foreach ($group as $analysis) {
                    $sum = '0.000000';
                    $values = [];
                    foreach (self::CAMPOS as $key => $field) {
                        $value = $analysis->{$key};
                        if ($value === null) {
                            $missing[] = $analysis->uuid_publico.': '.$key;

                            continue;
                        } $score = $this->points($value, $rule['valores']['bandas'][$key]);
                        $sum = bcadd($sum, bcmul($score, (string) $field[3], 6), 6);
                        $values[$key] = ['valor' => $value, 'puntos' => $score];
                    } $evaluations[] = ['analisis' => $analysis->uuid_publico, 'parametros' => $values];
                    if (count($values) === 3) {
                        $scores[] = bcdiv($sum, '100', 6);
                    }
                }
                $score = null;
                if ($water) {
                    $score = '0.0000';
                } elseif (! $missing && $scores) {
                    if ($rule['valores']['agregacion'] === 'minimo') {
                        $score = array_reduce($scores, fn ($carry, $v) => $carry === null || bccomp($v, $carry, 6) < 0 ? $v : $carry);
                    } else {
                        $sum = array_reduce($scores, fn ($carry, $v) => bcadd($carry, $v, 8), '0');
                        $score = bcdiv($sum, (string) count($scores), 8);
                    } $score = bcadd($score, '0.00005', 4);
                }
                $routes = $group->filter(fn ($a) => $a->ruta !== null)->map(fn ($a) => ['codigo' => $a->ruta->codigo, 'nombre' => $a->ruta->nombre])->unique('codigo')->values()->all();
                $rows[] = ['productor_id' => $producerId, 'productor_snapshot' => $producer->only(['codigo', 'nombres', 'apellidos']), 'rutas_snapshot' => $routes, 'puntuacion' => $score, 'estado' => $score === null ? 'pendiente' : 'calculado', 'detalle' => ['agua_anadida' => $water, 'faltantes' => $missing, 'analisis' => $evaluations, 'agregacion' => $rule['valores']['agregacion']]];
            }
            usort($rows, function ($a, $b): int {
                if ($a['puntuacion'] === null && $b['puntuacion'] !== null) {
                    return 1;
                } if ($b['puntuacion'] === null && $a['puntuacion'] !== null) {
                    return -1;
                } $difference = bccomp($b['puntuacion'] ?? '0', $a['puntuacion'] ?? '0', 4);

                return $difference ?: strcmp($a['productor_snapshot']['codigo'], $b['productor_snapshot']['codigo']) ?: $a['productor_id'] <=> $b['productor_id'];
            });
            $position = 0;
            foreach ($rows as $row) {
                $row['posicion'] = $row['puntuacion'] === null ? null : ++$position;
                $run->resultados()->create($row);
            }
            $this->audit->record('ranking', $run->uuid, 'calculo', $actor, [], ['regla' => $rule, 'desde' => $start, 'hasta' => $end, 'resultados' => count($rows), 'fuentes' => count($sources)], $reason);

            return $run;
        });
    }

    private function points(string $value, array $bands): string
    {
        foreach ($bands as $index => $band) {
            if (bccomp($value, $band['minimo'], 4) >= 0 && (bccomp($value, $band['maximo'], 4) < 0 || ($index === array_key_last($bands) && bccomp($value, $band['maximo'], 4) === 0))) {
                return $band['puntos'];
            }
        } throw ValidationException::withMessages(['medicion' => 'Una medición no está cubierta por las bandas aprobadas.']);
    }
}
