<?php

namespace App\Application\Ranking;

use App\Application\Operacion\ReglasOperativas;
use App\Infrastructure\Ranking\CalculoRanking;
use App\Infrastructure\Rutas\RutaAcopio;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

final class ConsultarRanking
{
    public function __construct(private ReglasOperativas $rules, private GestionarRanking $service) {}

    public function publicData(array $input): array
    {
        $data = Validator::make($input, ['tipo' => ['required', 'in:diario,semanal,mensual'], 'fecha' => ['required', 'date_format:Y-m-d'], 'ruta_id' => ['nullable', 'integer']], ['required' => 'Completa :attribute.', 'in' => 'Periodo inválido.', 'date_format' => 'Fecha inválida.', 'integer' => 'Ruta inválida.'])->validate();
        $rule = $this->rules->current('ranking');
        if (! ($rule['valores']['activo'] ?? false)) {
            return ['periodo' => null, 'resultados' => [], 'message' => 'El Muro de Honor aún no está habilitado.'];
        } [$start,$end] = $this->service->period($data['tipo'], $data['fecha'], $rule['valores']);
        $run = CalculoRanking::with(['resultados' => fn ($q) => $q->whereNotNull('puntuacion')->orderBy('posicion')])->where('vigente', true)->where('tipo', $data['tipo'])->whereDate('desde', $start)->whereDate('hasta', $end)->where('ruta_id', empty($data['ruta_id']) ? null : (int) $data['ruta_id'])->latest('id')->first();
        if (! $run) {
            return ['periodo' => null, 'resultados' => [], 'message' => 'No hay un cálculo vigente para este periodo y ruta.'];
        }
        $privacy = $rule['valores']['privacidad'];

        return ['periodo' => ['tipo' => $run->tipo, 'desde' => $run->desde->toDateString(), 'hasta' => $run->hasta->toDateString(), 'calculado_at' => $run->created_at->toIso8601String(), 'version_reglas' => $run->regla_aplicada['version'], 'algoritmo' => $run->algoritmo], 'resultados' => $run->resultados->map(function ($r) use ($privacy): array {
            $p = $r->productor_snapshot;
            $name = match ($privacy) {
                'nombre_completo' => trim($p['nombres'].' '.$p['apellidos']),'nombre_abreviado' => explode(' ', trim($p['nombres']))[0].' '.mb_substr(trim($p['apellidos']), 0, 1).'.',default => $p['codigo']
            };

            return ['posicion' => $r->posicion, 'productor' => $name, 'rutas' => $r->rutas_snapshot, 'puntuacion' => $r->puntuacion];
        })->all()];
    }

    public function history(int $actor): LengthAwarePaginator
    {
        $this->service->authorize($actor);

        return CalculoRanking::withCount('resultados')->latest('id')->paginate(15);
    }

    public function detail(int $actor, string $uuid): CalculoRanking
    {
        $this->service->authorize($actor);

        return CalculoRanking::with(['resultados' => fn ($q) => $q->orderByRaw('posicion IS NULL')->orderBy('posicion')->orderBy('id')])->where('uuid', $uuid)->firstOrFail();
    }

    public function routes(): array
    {
        return RutaAcopio::orderBy('codigo')->get(['id', 'codigo', 'nombre'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->codigo.' — '.$r->nombre])->all();
    }
}
