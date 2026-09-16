<?php

namespace App\Application\Reportes;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ConsultarReportes
{
    public const TYPES = ['volumen-productor' => 'Volumen por productor', 'volumen-ruta' => 'Volumen por ruta', 'volumen-recolector' => 'Volumen por recolector', 'volumen-periodo' => 'Volumen por día del periodo', 'conciliacion' => 'Diferencias campo/planta', 'alertas-conciliacion' => 'Alertas de conciliación', 'calidad-productor' => 'Calidad por productor', 'calidad-ruta' => 'Calidad por ruta', 'agua-anadida' => 'Agua añadida', 'acidez' => 'Acidez', 'asistencias' => 'Asistencias técnicas', 'produccion' => 'Producción y rendimiento', 'inventario' => 'Movimientos de inventario', 'ventas' => 'Ventas', 'ranking' => 'Ranking vigente', 'penalizaciones' => 'Penalizaciones', 'liquidaciones' => 'Liquidaciones', 'pagos' => 'Pagos'];

    public function options(int $actor): array
    {
        $u = User::findOrFail($actor);
        abort_unless($u->active, 403);
        if ($u->isActiveAdministrator()) {
            return self::TYPES;
        } abort_unless($u->hasRole('contador', 'web'), 403);

        return array_intersect_key(self::TYPES, array_flip(['liquidaciones', 'pagos']));
    }

    public function query(int $actor, string $type, array $input = []): Builder
    {
        abort_unless(array_key_exists($type, $this->options($actor)), 403);
        $f = Validator::make($input, ['desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'], 'ruta_id' => ['nullable', 'integer', 'min:1'], 'productor_id' => ['nullable', 'integer', 'min:1'], 'recolector_id' => ['nullable', 'integer', 'min:1']], ['date_format' => 'Usa fecha AAAA-MM-DD.', 'after_or_equal' => 'La fecha final debe ser posterior o igual a la inicial.', 'integer' => 'Identificador inválido.', 'min' => 'Identificador inválido.'])->validate();
        $date = null;
        $route = null;
        $producer = null;
        $collector = null;
        if (str_starts_with($type, 'volumen-')) {
            $q = DB::table('entregas_acopio as e')->join('jornadas_acopio as j', 'j.id', '=', 'e.jornada_id')->join('productores as p', 'p.id', '=', 'e.productor_id')->join('rutas_acopio as r', 'r.id', '=', 'e.ruta_id')->leftJoin('users as u', 'u.id', '=', 'e.recolector_id')->where('j.estado', '!=', 'anulada');
            $date = 'e.recolectada_at';
            $route = 'e.ruta_id';
            $producer = 'e.productor_id';
            $collector = 'e.recolector_id';
            $fields = match ($type) {
                'volumen-productor' => ['p.codigo', 'p.nombres', 'p.apellidos'],'volumen-ruta' => ['r.codigo', 'r.nombre'],'volumen-recolector' => ['e.recolector_id', 'u.name'],default => []
            };
            if ($fields) {
                $q->select($fields)->selectRaw('SUM(e.litros) as litros, COUNT(*) as entregas')->groupBy($fields)->orderBy($fields[0]);
            } else {
                $q->selectRaw('DATE(e.recolectada_at) as fecha, SUM(e.litros) as litros, COUNT(*) as entregas')->groupByRaw('DATE(e.recolectada_at)')->orderBy('fecha');
            }
        } elseif (in_array($type, ['conciliacion', 'alertas-conciliacion'], true)) {
            $q = DB::table('recepciones_planta as r')->where('r.resultado', '!=', 'anulada');
            $date = 'r.recibida_at';
            $route = 'r.ruta_id';
            $collector = 'r.recolector_id';
            if ($type === 'conciliacion') {
                $q->select(['r.uuid_publico', 'r.recibida_at', 'r.ruta_id', 'r.recolector_id', 'r.litros_campo', 'r.litros_planta', 'r.diferencia_litros', 'r.diferencia_porcentaje', 'r.tolerancia_porcentaje', 'r.resultado']);
            } else {
                $q->join('alertas_conciliacion as a', 'a.recepcion_id', '=', 'r.id')->select(['r.uuid_publico', 'r.recibida_at', 'r.ruta_id', 'r.recolector_id', 'a.litros_campo', 'a.litros_planta', 'a.diferencia_litros', 'a.diferencia_porcentaje', 'a.estado']);
            } $q->orderBy('r.id');
        } elseif (in_array($type, ['calidad-productor', 'calidad-ruta', 'agua-anadida', 'acidez'], true)) {
            $q = DB::table('analisis_calidad as a')->join('productores as p', 'p.id', '=', 'a.productor_id')->where('a.estado', '!=', 'anulado');
            $date = 'a.muestra_at';
            $route = 'a.ruta_id';
            $producer = 'a.productor_id';
            $q->select(['a.uuid_publico', 'p.codigo', 'a.ruta_id', 'a.muestra_at', 'a.grasa', 'a.proteina', 'a.lactosa', 'a.densidad_medida', 'a.temperatura', 'a.densidad_corregida', 'a.solidos_no_grasos', 'a.solidos_totales', 'a.ph', 'a.acidez', 'a.agua_anadida', 'a.estado']);
            if ($type === 'agua-anadida') {
                $q->where('a.agua_anadida', '>', 0);
            } $q->orderBy($type === 'calidad-ruta' ? 'a.ruta_id' : 'p.codigo')->orderBy('a.id');
        } elseif ($type === 'asistencias') {
            $q = DB::table('asistencias_tecnicas as a')->join('productores as p', 'p.id', '=', 'a.productor_id')->select(['a.uuid', 'p.codigo', 'a.estado', 'a.fecha_at', 'a.responsable_id', 'a.observaciones', 'a.fuente_vigente', 'a.requiere_revision'])->orderBy('a.id');
            $date = 'a.created_at';
            $producer = 'a.productor_id';
        } elseif ($type === 'produccion') {
            $adjustments = DB::table('ajustes_produccion')->selectRaw('lote_id, SUM(delta_moldes) as ajustes')->groupBy('lote_id');
            $q = DB::table('lotes_produccion as l')->leftJoinSub($adjustments, 'a', 'a.lote_id', '=', 'l.id')->where('l.estado', '!=', 'anulado')->select(['l.uuid', 'l.codigo', 'l.producido_at', 'l.tipo', 'l.litros_cuba', 'l.moldes', 'l.rendimiento', 'l.estado'])->selectRaw('COALESCE(a.ajustes,0) as ajustes_moldes, l.moldes + COALESCE(a.ajustes,0) as moldes_actuales')->orderBy('l.id');
            $date = 'l.producido_at';
        } elseif ($type === 'inventario') {
            $q = DB::table('movimientos_inventario')->select(['clave', 'tipo', 'clase', 'delta', 'anterior', 'nuevo', 'motivo', 'created_at'])->orderBy('id');
            $date = 'created_at';
        } elseif ($type === 'ventas') {
            $q = DB::table('ventas')->whereIn('estado', ['confirmada', 'pagada'])->select(['uuid', 'vendida_at', 'productor_id', 'subtotal', 'descuento', 'total', 'estado', 'descontar_liquidacion', 'metodo_pago', 'pagada_at'])->orderBy('id');
            $date = 'vendida_at';
            $producer = 'productor_id';
        } elseif ($type === 'ranking') {
            $q = DB::table('resultados_ranking as r')->join('calculos_ranking as c', 'c.id', '=', 'r.calculo_id')->join('productores as p', 'p.id', '=', 'r.productor_id')->where('c.vigente', true)->select(['c.uuid', 'c.tipo', 'c.desde', 'c.hasta', 'c.ruta_id', 'p.codigo', 'r.posicion', 'r.puntuacion', 'r.estado', 'c.algoritmo'])->orderBy('c.id')->orderBy('r.posicion');
            $date = 'c.desde';
            $route = 'c.ruta_id';
            $producer = 'r.productor_id';
        } elseif ($type === 'penalizaciones') {
            $q = DB::table('sanciones_calidad as s')->join('productores as p', 'p.id', '=', 's.productor_id')->select(['s.uuid', 'p.codigo', 's.tipo', 's.estado', 's.numero_falta', 's.agua_anadida', 's.tarifa_penalizada', 's.decision_perdida', 's.decision_expulsion', 's.requiere_revision', 's.created_at'])->orderBy('s.id');
            $date = 's.created_at';
            $producer = 's.productor_id';
        } else {
            $q = DB::table('liquidaciones as l')->join('periodos_liquidacion as p', 'p.id', '=', 'l.periodo_id')->join('productores as pr', 'pr.id', '=', 'l.productor_id')->where('l.estado', '!=', 'anulada');
            $producer = 'l.productor_id';
            if ($type === 'pagos') {
                $q->join('pagos_liquidacion as pg', 'pg.liquidacion_id', '=', 'l.id')->select(['pg.uuid_externo', 'l.uuid', 'pr.codigo', 'p.desde', 'p.hasta', 'pg.importe', 'pg.metodo', 'pg.pagado_at'])->orderBy('pg.id');
                $date = 'pg.pagado_at';
            } else {
                $adjustments = DB::table('ajustes_liquidacion')->where('estado', 'aprobado')->selectRaw("liquidacion_id, SUM(CASE WHEN tipo='bono' THEN importe ELSE 0 END) as bonos, SUM(CASE WHEN tipo='ajuste' THEN importe ELSE 0 END) as ajustes")->groupBy('liquidacion_id');
                $q->leftJoinSub($adjustments, 'aj', 'aj.liquidacion_id', '=', 'l.id');
                $q->select(['l.uuid', 'pr.codigo', 'p.desde', 'p.hasta', 'l.litros_total', 'l.precio_litro', 'l.importe_bruto', 'l.penalizaciones', 'l.descuentos_queso', 'l.total_base', 'l.estado'])->selectRaw("COALESCE(aj.bonos,0) as bonos, COALESCE(aj.ajustes,0) as ajustes, l.total_base + COALESCE(aj.ajustes,0) + CASE WHEN l.perdida_liquidacion=1 AND JSON_EXTRACT(l.regla_aplicada,'$.valores.perdida_incluye_bonos') IN (1,'1',true) THEN 0 ELSE COALESCE(aj.bonos,0) END as total_actual")->orderBy('l.id');
                $date = 'p.desde';
            }
        }
        if (! empty($f['desde'])) {
            $q->whereDate($date, '>=', $f['desde']);
        } if (! empty($f['hasta'])) {
            $q->whereDate($date, '<=', $f['hasta']);
        }
        foreach (['ruta_id' => $route, 'productor_id' => $producer, 'recolector_id' => $collector] as $key => $column) {
            if (! empty($f[$key])) {
                if (! $column) {
                    throw ValidationException::withMessages([$key => 'Este filtro no corresponde al reporte seleccionado.']);
                } $q->where($column, $f[$key]);
            }
        }

        return $q;
    }

    public function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+@-]/u', $text) ? "'".$text : $text;
    }
}
