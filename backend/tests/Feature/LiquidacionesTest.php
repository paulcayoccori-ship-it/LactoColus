<?php

namespace Tests\Feature;

use App\Application\Inventario\GestionarInventario;
use App\Application\Liquidaciones\CalcularLiquidaciones;
use App\Application\Liquidaciones\GestionarLiquidaciones;
use App\Application\Liquidaciones\GestionarPeriodos;
use App\Application\Liquidaciones\ProtegerFuentesLiquidacion;
use App\Application\Penalizaciones\GestionarPenalizaciones;
use App\Application\Ventas\GestionarVentas;
use App\Domain\Dashboard\DashboardRepository;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Comunicados\CuentaProductor;
use App\Infrastructure\Liquidaciones\Liquidacion;
use App\Infrastructure\Liquidaciones\PeriodoLiquidacion;
use App\Infrastructure\Penalizaciones\SancionCalidad;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Ventas\Cliente;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LiquidacionesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'administrador'): User
    {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(['active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function period(User $admin): PeriodoLiquidacion
    {
        $this->travelTo(now()->setDate(2026, 9, 10)->setTime(12, 0));

        return app(GestionarPeriodos::class)->create($admin->id, ['uuid' => (string) Str::uuid(), 'desde' => '2026-09-03', 'motivo' => 'Periodo semanal']);
    }

    private function calculated(User $admin): Liquidacion
    {
        $p = $this->period($admin);
        $j = JornadaAcopio::factory()->create(['estado' => 'cerrada', 'fecha_operativa' => '2026-09-03']);
        EntregaAcopio::factory()->create(['jornada_id' => $j->id, 'litros' => '10.125', 'recolectada_at' => '2026-09-03 10:00:00']);
        app(GestionarPeriodos::class)->close($admin->id, $p->uuid, 'Fuentes revisadas');
        app(CalcularLiquidaciones::class)->calculate($admin->id, $p->uuid, 'Consolidación');

        return Liquidacion::sole();
    }

    public function test_calendar_snapshots_calculation_and_payment_are_exact_and_idempotent(): void
    {
        $a = $this->actor();
        $c = $this->actor('contador');
        $l = $this->calculated($a);
        $p = $l->periodo;
        $this->assertSame('2026-09-09', $p->hasta->toDateString());
        $this->assertSame('2026-09-11', $p->pago_previsto->toDateString());
        $this->assertSame('10.125', $l->litros_total);
        $this->assertSame('17.21', $l->total_base);
        $this->assertCount(7, $l->litros_diarios);
        app(CalcularLiquidaciones::class)->calculate($c->id, $p->uuid, 'Reintento');
        $this->assertDatabaseCount('liquidaciones', 1);
        $s = app(GestionarLiquidaciones::class);
        $s->approve($a->id, $p->uuid, 'Aprobación');
        $input = ['uuid_externo' => (string) Str::uuid(), 'metodo' => 'Transferencia', 'pagado_at' => now()->toDateTimeString()];
        $payment = $s->pay($c->id, $l->uuid, $input);
        $this->assertSame($payment->id, $s->pay($c->id, $l->uuid, $input)->id);
        $this->assertSame('17.21', $payment->importe);
        $this->assertSame('pagado', $p->fresh()->estado);
        $this->assertDatabaseCount('pagos_liquidacion', 1);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'pago']);
    }

    public function test_adjustments_require_approval_and_preserve_base(): void
    {
        $a = $this->actor();
        $c = $this->actor('contador');
        $l = $this->calculated($a);
        $s = app(GestionarLiquidaciones::class);
        $adjust = $s->adjustment($c->id, $l->uuid, ['uuid' => (string) Str::uuid(), 'tipo' => 'bono', 'importe' => '2.50', 'motivo' => 'Bono documentado']);
        try {
            $s->approve($a->id, $l->periodo->uuid, 'Revisión');
            $this->fail('Debe bloquear ajustes pendientes.');
        } catch (ValidationException) {
        } $s->decideAdjustment($a->id, $adjust->uuid, ['decision' => 'aprobado', 'motivo' => 'Bono autorizado']);
        $s->approve($a->id, $l->periodo->uuid, 'Aprobación');
        $this->assertSame('19.71', $s->totals($l->fresh())['total']);
        $this->assertSame('17.21', $l->fresh()->total_base);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'decision_ajuste']);
    }

    public function test_duplicate_period_is_rejected(): void
    {
        $a = $this->actor();
        $this->period($a);
        $this->expectException(ValidationException::class);
        $this->period($a);
    }

    public function test_open_journey_prevents_close(): void
    {
        $a = $this->actor();
        $p = $this->period($a);
        JornadaAcopio::factory()->create(['estado' => 'abierta', 'fecha_operativa' => '2026-09-04']);
        $this->expectException(ValidationException::class);
        app(GestionarPeriodos::class)->close($a->id, $p->uuid, 'Cierre');
    }

    #[TestWith(['contador'])] #[TestWith(['recolector'])] #[TestWith(['supervisor'])] #[TestWith(['calidad'])] #[TestWith(['productor'])]
    public function test_non_admin_cannot_create_period(string $role): void
    {
        $a = $this->actor($role);
        $this->expectException(AuthorizationException::class);
        $this->period($a);
    }

    public function test_paid_period_cannot_be_annulled_or_recalculated(): void
    {
        $a = $this->actor();
        $l = $this->calculated($a);
        $s = app(GestionarLiquidaciones::class);
        $s->approve($a->id, $l->periodo->uuid, 'Aprobación');
        $s->pay($a->id, $l->uuid, ['uuid_externo' => (string) Str::uuid(), 'metodo' => 'Efectivo', 'pagado_at' => now()->toDateTimeString()]);
        try {
            app(CalcularLiquidaciones::class)->calculate($a->id, $l->periodo->uuid, 'Recálculo');
            $this->fail('No debe recalcular.');
        } catch (ValidationException) {
        } $this->expectException(ValidationException::class);
        app(GestionarPeriodos::class)->annul($a->id, $l->periodo->uuid, 'Anulación');
    }

    public function test_api_isolates_producer_and_panel_recovers_after_validation(): void
    {
        $a = $this->actor();
        $l = $this->calculated($a);
        $p = $this->actor('productor');
        CuentaProductor::create(['usuario_id' => $p->id, 'productor_id' => $l->productor_id, 'activa' => true]);
        Sanctum::actingAs($p);
        $this->getJson('/api/v1/liquidaciones/'.$l->uuid)->assertNotFound();
        app(GestionarLiquidaciones::class)->approve($a->id, $l->periodo->uuid, 'Aprobación');
        $this->getJson('/api/v1/liquidaciones/'.$l->uuid)->assertOk()->assertJsonPath('data.total_base', '17.21');
        $this->postJson('/api/v1/liquidaciones/'.$l->uuid.'/pagar', [])->assertForbidden();
        $this->getJson('/api/v1/liquidaciones/periodos')->assertForbidden();
        $other = $this->actor('productor');
        CuentaProductor::create(['usuario_id' => $other->id, 'productor_id' => Productor::factory()->create()->id, 'activa' => true]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/liquidaciones/'.$l->uuid)->assertNotFound();
        $this->actingAs($a);
        Livewire::test('pages::liquidaciones.index')->call('createPeriod')->assertHasErrors('desde')->set('periodForm.desde', '2026-09-10')->set('periodForm.motivo', 'Nueva semana')->call('createPeriod')->assertHasNoErrors()->call('show', $l->uuid)->assertSee('17.21');
    }

    public function test_pdf_has_valid_cross_reference_and_escapes_text(): void
    {
        $a = $this->actor();
        $l = $this->calculated($a);
        $this->actingAs($a)->get('/admin/liquidaciones/'.$l->uuid.'/comprobante')->assertOk()->assertSee('17.21');
        $response = $this->get('/admin/liquidaciones/'.$l->uuid.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('(TOTAL: S/ 17.21)', $pdf);
        preg_match('/startxref\n(\d+)/', $pdf, $match);
        $this->assertSame('xref', substr($pdf, (int) $match[1], 4));
    }

    public function test_closing_blocks_late_source_writes_and_annulment_preserves_snapshots(): void
    {
        $a = $this->actor();
        $l = $this->calculated($a);
        try {
            app(ProtegerFuentesLiquidacion::class)->date('2026-09-04');
            $this->fail('Debe bloquear fechas cerradas.');
        } catch (ValidationException) {
        }
        app(GestionarPeriodos::class)->annul($a->id, $l->periodo->uuid, 'Cierre incorrecto');
        app(ProtegerFuentesLiquidacion::class)->date('2026-09-04');
        $this->assertSame('anulada', $l->fresh()->estado);
        $this->assertDatabaseCount('periodo_entregas', 1);
        $this->assertDatabaseCount('entregas_acopio', 1);
    }

    public function test_tariff_snapshot_does_not_change_after_new_configuration(): void
    {
        $admin = $this->actor();
        $l = $this->calculated($admin);
        app(GestionarPeriodos::class)->configure($admin->id, ['precio_litro' => '2.00', 'conflicto_tarifas' => 'bloquear', 'perdida_incluye_bonos' => null, 'motivo' => 'Tarifa futura']);
        $this->assertSame('1.70', $l->fresh()->precio_litro);
        $this->assertSame('17.21', $l->fresh()->total_base);
    }

    public function test_negative_total_requires_approved_adjustment_before_payment(): void
    {
        $a = $this->actor();
        $l = $this->calculated($a);
        $s = app(GestionarLiquidaciones::class);
        $adjust = $s->adjustment($a->id, $l->uuid, ['uuid' => (string) Str::uuid(), 'tipo' => 'ajuste', 'importe' => '-20.00', 'motivo' => 'Descuento documentado']);
        $s->decideAdjustment($a->id, $adjust->uuid, ['decision' => 'aprobado', 'motivo' => 'Verificado']);
        $this->expectException(ValidationException::class);
        $s->approve($a->id, $l->periodo->uuid, 'Revisión de saldo');
    }

    public function test_dashboard_counts_unpaid_and_paid_liquidations(): void
    {
        $a = $this->actor();
        $l = $this->calculated($a);
        $s = app(GestionarLiquidaciones::class);
        $q = app(DashboardRepository::class);
        $this->assertSame(1, $q->summary()['liquidaciones_pendientes']);
        $s->approve($a->id, $l->periodo->uuid, 'Aprobación');
        $s->pay($a->id, $l->uuid, ['uuid_externo' => (string) Str::uuid(), 'metodo' => 'Efectivo', 'pagado_at' => now()->toDateTimeString()]);
        $result = $q->summary();
        $this->assertSame(0, $result['liquidaciones_pendientes']);
        $this->assertSame(1, $result['pagos_hoy']);
        $this->assertSame('17.21', $result['importe_pagado_hoy']);
    }

    public function test_approved_penalty_is_copied_and_applied_without_changing_deliveries(): void
    {
        $a = $this->actor();
        $period = $this->period($a);
        $journey = JornadaAcopio::factory()->create(['estado' => 'cerrada', 'fecha_operativa' => '2026-09-03']);
        $delivery = EntregaAcopio::factory()->create(['jornada_id' => $journey->id, 'litros' => '10.125', 'recolectada_at' => '2026-09-03 10:00:00']);
        $analysis = AnalisisCalidad::factory()->create(['productor_id' => $delivery->productor_id, 'agua_anadida' => '1.0000', 'muestra_at' => '2026-09-03 10:00:00']);
        $penalties = app(GestionarPenalizaciones::class);
        $penalties->configure($a->id, ['activo' => true, 'tarifa_primera' => '1.20', 'tarifa_grave' => '0.65', 'alcance_tarifa' => 'dia', 'unidad_falta' => 'analisis', 'ventana_dias' => null, 'motivo' => 'Tarifas sintéticas de prueba']);
        $penalties->recalculate($a->id, $delivery->productor_id, 'Revisión');
        $sanction = SancionCalidad::sole();
        try {
            app(GestionarPeriodos::class)->close($a->id, $period->uuid, 'Pendiente');
            $this->fail('Debe resolver sanción antes de cerrar.');
        } catch (ValidationException) {
        }
        $penalties->decide($a->id, $sanction->uuid, ['decision' => 'aprobada', 'motivo' => 'Revisión aprobada']);
        app(GestionarPeriodos::class)->close($a->id, $period->uuid, 'Cierre');
        app(CalcularLiquidaciones::class)->calculate($a->id, $period->uuid, 'Cálculo');
        $l = Liquidacion::sole();
        $this->assertSame('12.15', $l->total_base);
        $this->assertSame('5.06', $l->penalizaciones);
        $penalties->annul($a->id, $sanction->uuid, 'Revisión posterior');
        $this->assertSame('12.15', $l->fresh()->total_base);
        $this->assertSame('10.125', $delivery->fresh()->litros);
        $this->assertSame('aprobada', $l->detalle_calculo['sanciones'][0]['estado']);
    }

    public function test_cheese_purchase_is_discounted_once_and_cannot_be_paid_separately(): void
    {
        $a = $this->actor();
        $period = $this->period($a);
        $j = JornadaAcopio::factory()->create(['estado' => 'cerrada', 'fecha_operativa' => '2026-09-03']);
        $d = EntregaAcopio::factory()->create(['jornada_id' => $j->id, 'litros' => '100.000', 'recolectada_at' => '2026-09-03 10:00:00']);
        $client = Cliente::factory()->create(['categoria' => 'proveedor', 'productor_id' => $d->productor_id]);
        app(GestionarInventario::class)->adjust($a->id, ['uuid' => (string) Str::uuid(), 'tipo' => 'paria_fresco', 'delta' => 5, 'motivo' => 'Existencia sintética de prueba']);
        $sales = app(GestionarVentas::class);
        $sales->pricing($a->id, ['precios' => ['mayorista' => '20.00', 'proveedor' => '18.00', 'publico_general' => '21.00'], 'limite_proveedor' => 15, 'alcance_limite' => 'venta'], 'Regla sintética');
        $sale = $sales->create($a->id, ['uuid' => (string) Str::uuid(), 'cliente_id' => $client->id, 'vendida_at' => '2026-09-04 10:00:00', 'descuento' => '0.00', 'descontar_liquidacion' => true, 'detalles' => [['tipo' => 'paria_fresco', 'moldes' => 2]]]);
        $sales->confirm($a->id, $sale->uuid);
        app(GestionarPeriodos::class)->close($a->id, $period->uuid, 'Cierre');
        try {
            $sales->pay($a->id, $sale->uuid, ['metodo_pago' => 'Efectivo', 'pagada_at' => now()->toDateTimeString()]);
            $this->fail('No debe pagar una venta vinculada.');
        } catch (ValidationException) {
        }
        try {
            $sales->annul($a->id, $sale->uuid, 'Cancelar');
            $this->fail('No debe anular una venta vinculada.');
        } catch (ValidationException) {
        }
        app(CalcularLiquidaciones::class)->calculate($a->id, $period->uuid, 'Cálculo');
        $l = Liquidacion::sole();
        $this->assertSame('36.00', $l->descuentos_queso);
        $this->assertSame('134.00', $l->total_base);
        $s = app(GestionarLiquidaciones::class);
        $s->approve($a->id, $period->uuid, 'Aprobación');
        $s->pay($a->id, $l->uuid, ['uuid_externo' => (string) Str::uuid(), 'metodo' => 'Transferencia', 'pagado_at' => now()->toDateTimeString()]);
        $this->assertSame('pagada', $sale->fresh()->estado);
        $this->assertSame('Liquidación semanal', $sale->fresh()->metodo_pago);
        $this->assertDatabaseCount('movimientos_inventario', 2);
    }
}
