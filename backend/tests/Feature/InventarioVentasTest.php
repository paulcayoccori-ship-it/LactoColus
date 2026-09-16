<?php

namespace Tests\Feature;

use App\Application\Inventario\GestionarInventario;
use App\Application\Produccion\GestionarProduccion;
use App\Application\Ventas\GestionarVentas;
use App\Domain\Dashboard\DashboardRepository;
use App\Infrastructure\Inventario\ExistenciaQueso;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Infrastructure\Ventas\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventarioVentasTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'administrador'): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function stock(User $actor, int $quantity = 10, string $type = 'paria_fresco'): void
    {
        app(GestionarInventario::class)->adjust($actor->id, ['uuid' => (string) Str::uuid(), 'tipo' => $type, 'delta' => $quantity, 'motivo' => 'Conteo inicial de prueba']);
    }

    private function payload(?Cliente $client = null, int $quantity = 2): array
    {
        $client ??= Cliente::factory()->create();

        return ['uuid' => (string) Str::uuid(), 'cliente_id' => $client->id, 'vendida_at' => now()->toDateTimeString(), 'descuento' => '0.00', 'descontar_liquidacion' => false, 'detalles' => [['tipo' => 'paria_fresco', 'moldes' => $quantity]]];
    }

    public function test_server_prices_ignore_manipulated_detail_and_repeated_uuid(): void
    {
        $admin = $this->actor();
        Sanctum::actingAs($admin);
        $data = $this->payload();
        $data['total'] = '0.01';
        $data['detalles'][0]['precio'] = '0.01';
        $data['detalles'][0]['venta_id'] = 999;
        $this->postJson('/api/v1/ventas', $data)->assertCreated()->assertJsonPath('data.total', '42.00')->assertJsonPath('data.detalles.0.precio', '21.00');
        $this->postJson('/api/v1/ventas', $data)->assertOk();
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('detalles_venta', 1);
    }

    public function test_confirmation_payment_and_annulment_are_idempotent(): void
    {
        $admin = $this->actor();
        $this->stock($admin);
        $s = app(GestionarVentas::class);
        $sale = $s->create($admin->id, $this->payload());
        $s->confirm($admin->id, $sale->uuid);
        $s->confirm($admin->id, $sale->uuid);
        $this->assertSame(8, ExistenciaQueso::find('paria_fresco')->moldes);
        $payment = ['metodo_pago' => 'Efectivo', 'pagada_at' => now()->toDateTimeString()];
        $s->pay($admin->id, $sale->uuid, $payment);
        $s->pay($admin->id, $sale->uuid, $payment);
        $this->assertDatabaseHas('ventas', ['uuid' => $sale->uuid, 'estado' => 'pagada']);
        $s->annul($admin->id, $sale->uuid, 'Venta equivocada');
        $s->annul($admin->id, $sale->uuid, 'Venta equivocada');
        $this->assertSame(10, ExistenciaQueso::find('paria_fresco')->moldes);
        $this->assertDatabaseCount('movimientos_inventario', 3);
        $this->assertDatabaseCount('ventas', 1);
    }

    public function test_multiple_types_roll_back_if_second_type_has_no_stock(): void
    {
        $admin = $this->actor();
        $this->stock($admin);
        Sanctum::actingAs($admin);
        $input = $this->payload();
        $input['detalles'][] = ['tipo' => 'paria_pasteurizado', 'moldes' => 1];
        $sale = app(GestionarVentas::class)->create($admin->id, $input);
        $this->postJson('/api/v1/ventas/'.$sale->uuid.'/confirmar')->assertUnprocessable()->assertJsonValidationErrors('stock');
        $this->assertSame(10, ExistenciaQueso::find('paria_fresco')->moldes);
        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertSame('borrador', $sale->fresh()->estado);
    }

    public function test_prices_and_client_are_historical_snapshots(): void
    {
        $admin = $this->actor();
        $s = app(GestionarVentas::class);
        $client = Cliente::factory()->create(['nombre' => 'Nombre original']);
        $sale = $s->create($admin->id, $this->payload($client));
        $s->pricing($admin->id, ['precios' => ['mayorista' => '25', 'proveedor' => '24', 'publico_general' => '26'], 'alcance_limite' => 'venta'], 'Nueva tarifa');
        $s->customer($admin->id, ['nombre' => 'Nombre corregido', 'categoria' => 'publico_general', 'activo' => true, 'motivo' => 'Nombre corregido'], $client->id);
        $this->assertSame('21.00', $sale->fresh()->detalles->sole()->precio);
        $this->assertSame('Nombre original', $sale->fresh()->cliente_snapshot['nombre']);
        $new = $s->create($admin->id, $this->payload($client));
        $this->assertSame('52.00', $new->total);
        $this->assertSame(1, $new->tarifa_aplicada['version']);
    }

    public function test_provider_requires_configured_limit_and_weekly_quantity_is_enforced(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(12, 0));
        $admin = $this->actor();
        $this->stock($admin, 100);
        $producer = Productor::factory()->create(['estado' => true]);
        $client = Cliente::factory()->create(['categoria' => 'proveedor', 'productor_id' => $producer->id]);
        $input = $this->payload($client, 12);
        $input['descontar_liquidacion'] = true;
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/ventas', $input)->assertUnprocessable()->assertJsonValidationErrors('tarifa');
        $s = app(GestionarVentas::class);
        $s->pricing($admin->id, ['precios' => ['mayorista' => '20', 'proveedor' => '18', 'publico_general' => '21'], 'limite_proveedor' => 20, 'alcance_limite' => 'semana_jueves_miercoles'], 'Acuerdo de límite');
        $sale = $s->create($admin->id, $input);
        $s->confirm($admin->id, $sale->uuid);
        $second = $s->create($admin->id, $this->payload($client, 9));
        $this->postJson('/api/v1/ventas/'.$second->uuid.'/confirmar')->assertUnprocessable()->assertJsonValidationErrors('limite');
        $this->assertSame('216.00', $sale->total);
        $this->assertTrue($sale->descontar_liquidacion);
        $this->assertSame(88, ExistenciaQueso::find('paria_fresco')->moldes);
    }

    public function test_inventory_adjustments_require_reason_and_cannot_make_stock_negative(): void
    {
        $admin = $this->actor();
        Sanctum::actingAs($admin);
        $input = ['uuid' => (string) Str::uuid(), 'tipo' => 'paria_fresco', 'delta' => -1, 'motivo' => ''];
        $this->postJson('/api/v1/ventas/inventario/ajustes', $input)->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $input['motivo'] = 'Reconteo';
        $this->postJson('/api/v1/ventas/inventario/ajustes', $input)->assertUnprocessable()->assertJsonValidationErrors('stock');
        $input['delta'] = 3;
        $this->postJson('/api/v1/ventas/inventario/ajustes', $input)->assertOk();
        $this->postJson('/api/v1/ventas/inventario/ajustes', $input)->assertOk();
        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertDatabaseHas('auditorias_operativas', ['modulo' => 'inventario', 'accion' => 'ajuste', 'motivo' => 'Reconteo']);
    }

    #[TestWith(['recolector'])] #[TestWith(['calidad'])] #[TestWith(['contador'])] #[TestWith(['supervisor'])]
    public function test_other_roles_cannot_manage_inventory_or_sales(string $role): void
    {
        $actor = $this->actor($role);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/ventas')->assertForbidden();
        $this->postJson('/api/v1/ventas/clientes', [])->assertForbidden();
        $this->actingAs($actor)->get('/admin/ventas')->assertForbidden();
    }

    public function test_guest_cannot_access_api(): void
    {
        $this->getJson('/api/v1/ventas')->assertUnauthorized();
    }

    public function test_invalid_quantities_discount_and_duplicate_types_are_rejected(): void
    {
        Sanctum::actingAs($this->actor());
        $input = $this->payload();
        $input['detalles'][0]['moldes'] = '1.5';
        $this->postJson('/api/v1/ventas', $input)->assertUnprocessable()->assertJsonValidationErrors('detalles.0.moldes');
        $input['detalles'][0]['moldes'] = 1;
        $input['detalles'][] = $input['detalles'][0];
        $this->postJson('/api/v1/ventas', $input)->assertUnprocessable();
        array_pop($input['detalles']);
        $input['descuento'] = '22.00';
        $this->postJson('/api/v1/ventas', $input)->assertUnprocessable()->assertJsonValidationErrors('descuento');
        $input['descuento'] = '0.001';
        $this->postJson('/api/v1/ventas', $input)->assertUnprocessable();
    }

    public function test_production_movements_and_dashboard_follow_real_operations(): void
    {
        $admin = $this->actor();
        $receipt = RecepcionPlanta::factory()->create(['litros_planta' => '100.000']);
        $p = app(GestionarProduccion::class);
        $lot = $p->create($admin->id, ['uuid' => (string) Str::uuid(), 'codigo' => 'REAL-1', 'tipo' => 'paria_fresco', 'producido_at' => now()->toDateTimeString(), 'recepciones' => [['recepcion_id' => $receipt->id, 'litros' => '100']]]);
        $p->start($admin->id, $lot->uuid);
        $p->finish($admin->id, $lot->uuid, 11);
        $p->finish($admin->id, $lot->uuid, 11);
        $p->adjust($admin->id, $lot->uuid, ['uuid' => (string) Str::uuid(), 'delta_moldes' => 1, 'motivo' => 'Conteo final']);
        $s = app(GestionarVentas::class);
        $sale = $s->create($admin->id, $this->payload());
        $s->confirm($admin->id, $sale->uuid);
        $summary = app(DashboardRepository::class)->summary();
        $this->assertSame('100.000', $summary['litros_procesados']);
        $this->assertSame(12, $summary['moldes_producidos']);
        $this->assertSame('12.000000', $summary['rendimiento_promedio']);
        $this->assertSame(10, $summary['stock_queso']['paria_fresco']);
        $this->assertSame('42.00', $summary['importe_ventas_hoy']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/produccion/lotes/'.$lot->uuid.'/anular', ['motivo' => 'Error'])->assertUnprocessable()->assertJsonValidationErrors('stock');
        $s->annul($admin->id, $sale->uuid, 'Devolución');
        $p->annul($admin->id, $lot->uuid, 'Lote equivocado');
        $summary = app(DashboardRepository::class)->summary();
        $this->assertSame(0, $summary['moldes_producidos']);
        $this->assertSame(0, $summary['ventas_hoy']);
        $this->assertSame(0, $summary['stock_queso']['paria_fresco']);
    }

    public function test_panel_recovers_after_validation_failure_and_displays_details(): void
    {
        $admin = $this->actor();
        $input = $this->payload();
        $this->actingAs($admin);
        Livewire::test('pages::ventas.index')->call('create')->call('save')->assertHasErrors('cliente_id')->assertSet('modal', true)->set('form', $input)->call('save')->assertHasNoErrors()->assertSet('modal', false)->assertSee('42.00')->call('runAction', 'confirmar')->assertHasErrors('stock');
    }
}
