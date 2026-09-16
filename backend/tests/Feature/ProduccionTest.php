<?php

namespace Tests\Feature;

use App\Application\Operacion\ReglasOperativas;
use App\Application\Produccion\GestionarProduccion;
use App\Application\Recepciones\GestionarRecepciones;
use App\Infrastructure\Operacion\AuditoriaOperativa;
use App\Infrastructure\Produccion\UsoRecepcion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProduccionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'administrador'): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(?RecepcionPlanta $receipt = null, string $liters = '100.000'): array
    {
        $receipt ??= RecepcionPlanta::factory()->create(['litros_planta' => '100.000']);

        return ['uuid' => (string) Str::uuid(), 'codigo' => 'LOTE-'.Str::random(8), 'tipo' => 'paria_fresco', 'producido_at' => now()->toDateTimeString(), 'recepciones' => [['recepcion_id' => $receipt->id, 'litros' => $liters]]];
    }

    public function test_reservation_sums_sources_server_side_and_uuid_is_idempotent(): void
    {
        $admin = $this->actor();
        Sanctum::actingAs($admin);
        $input = $this->payload();
        $input['litros_cuba'] = '1';
        $r = $this->postJson('/api/v1/produccion/lotes', $input)->assertCreated()->assertJsonPath('data.litros_cuba', '100.000')->assertJsonPath('data.estado', 'borrador');
        $this->postJson('/api/v1/produccion/lotes', $input)->assertOk()->assertJsonPath('data.uuid', $r->json('data.uuid'));
        $this->assertDatabaseCount('lotes_produccion', 1);
        $this->assertDatabaseHas('usos_recepcion', ['litros' => '100.000', 'estado' => 'reservado']);
    }

    public function test_insufficient_milk_rolls_back_all_reservations(): void
    {
        $admin = $this->actor();
        $receipt = RecepcionPlanta::factory()->create(['litros_planta' => '100.000']);
        $service = app(GestionarProduccion::class);
        $service->create($admin->id, $this->payload($receipt, '70.000'));
        $input = $this->payload($receipt, '40.000');
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/produccion/lotes', $input)->assertUnprocessable()->assertJsonValidationErrors('recepciones');
        $this->assertSame('70.000', UsoRecepcion::occupied($receipt->id));
        $this->assertDatabaseCount('lotes_produccion', 1);
    }

    #[TestWith([11, '11.000000', false])]
    #[TestWith([12, '12.000000', false])]
    #[TestWith([10, '10.000000', true])]
    #[TestWith([13, '13.000000', true])]
    public function test_finalization_calculates_yield_and_alerts(int $molds, string $yield, bool $alert): void
    {
        $admin = $this->actor();
        $service = app(GestionarProduccion::class);
        $lot = $service->create($admin->id, $this->payload());
        $service->start($admin->id, $lot->uuid);
        $final = $service->finish($admin->id, $lot->uuid, $molds);
        $this->assertSame($yield, $final->rendimiento);
        $this->assertSame($alert, $final->alerta()->exists());
        $service->finish($admin->id, $lot->uuid, $molds);
        $this->assertSame(1, AuditoriaOperativa::where('accion', 'finalizacion')->count());
        $this->assertDatabaseHas('usos_recepcion', ['lote_id' => $lot->id, 'estado' => 'consumido']);
    }

    public function test_finalized_lot_is_immutable_adjustments_are_audited_and_idempotent(): void
    {
        $admin = $this->actor();
        $service = app(GestionarProduccion::class);
        $lot = $service->create($admin->id, $this->payload());
        $service->start($admin->id, $lot->uuid);
        $service->finish($admin->id, $lot->uuid, 10);
        $adjust = ['uuid' => (string) Str::uuid(), 'delta_moldes' => 1, 'motivo' => 'Conteo verificado'];
        $service->adjust($admin->id, $lot->uuid, $adjust);
        $service->adjust($admin->id, $lot->uuid, $adjust);
        $this->assertSame(10, $lot->fresh()->moldes);
        $this->assertSame(11, $service->effectiveMolds($lot->fresh()));
        $this->assertDatabaseCount('ajustes_produccion', 1);
        $this->assertSame('resuelta', $lot->fresh()->alerta->estado);
        $audit = AuditoriaOperativa::where('accion', 'ajuste')->sole();
        $this->assertSame(10, $audit->anteriores['moldes_efectivos']);
        $this->assertSame(11, $audit->nuevos['moldes_efectivos']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/produccion/lotes/'.$lot->uuid.'/finalizar', ['moldes' => 12])->assertUnprocessable();
    }

    public function test_annulment_releases_draft_but_not_consumed_milk(): void
    {
        $admin = $this->actor();
        $service = app(GestionarProduccion::class);
        $receipt = RecepcionPlanta::factory()->create(['litros_planta' => '100.000']);
        $lot = $service->create($admin->id, $this->payload($receipt));
        $service->annul($admin->id, $lot->uuid, 'Borrador equivocado');
        $this->assertSame('0.000', UsoRecepcion::occupied($receipt->id));
        $next = $service->create($admin->id, $this->payload($receipt));
        $service->start($admin->id, $next->uuid);
        $service->annul($admin->id, $next->uuid, 'Problema de proceso');
        $this->assertSame('100.000', UsoRecepcion::occupied($receipt->id));
        $this->assertDatabaseCount('lotes_produccion', 2);
    }

    public function test_reception_cannot_be_corrected_below_allocated_milk(): void
    {
        $admin = $this->actor();
        $receipt = RecepcionPlanta::factory()->create(['litros_planta' => '100.000']);
        app(GestionarProduccion::class)->create($admin->id, $this->payload($receipt));
        $this->expectException(ValidationException::class);
        app(GestionarRecepciones::class)->correct($admin->id, $receipt->id, ['litros_planta' => '90', 'motivo' => 'Relectura']);
    }

    public function test_reception_cannot_be_annulled_while_in_use(): void
    {
        $admin = $this->actor();
        $receipt = RecepcionPlanta::factory()->create(['litros_planta' => '100.000']);
        app(GestionarProduccion::class)->create($admin->id, $this->payload($receipt));
        $this->expectException(ValidationException::class);
        app(GestionarRecepciones::class)->annul($admin->id, $receipt->id, 'Prueba');
    }

    public function test_rules_are_versioned_and_existing_lots_keep_old_range(): void
    {
        $admin = $this->actor();
        $service = app(GestionarProduccion::class);
        $lot = $service->create($admin->id, $this->payload());
        app(ReglasOperativas::class)->saveProduction($admin->id, ['minimo' => '20', 'maximo' => '25'], 'Nuevo proceso');
        $service->start($admin->id, $lot->uuid);
        $final = $service->finish($admin->id, $lot->uuid, 11);
        $this->assertSame('11.000', $final->regla_aplicada['valores']['minimo']);
        $this->assertFalse($final->alerta()->exists());
        $new = $service->create($admin->id, $this->payload());
        $this->assertSame(1, $new->regla_aplicada['version']);
    }

    #[TestWith(['calidad'])]
    #[TestWith(['recolector'])]
    #[TestWith(['contador'])]
    #[TestWith(['supervisor'])]
    public function test_other_roles_cannot_manage_production(string $role): void
    {
        $actor = $this->actor($role);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/produccion/lotes')->assertForbidden();
        $this->actingAs($actor)->get('/admin/produccion')->assertForbidden();
    }

    public function test_panel_recovers_after_validation_and_can_correct_draft(): void
    {
        $admin = $this->actor();
        $input = $this->payload();
        $this->actingAs($admin);
        $panel = Livewire::test('pages::produccion.index')->call('create')->call('save')->assertHasErrors('codigo')->assertSet('modal', true);
        $panel->set('form', $input)->call('save')->assertHasNoErrors()->assertSet('modal', false);
        $panel->call('edit')->set('form.codigo', 'CORREGIDO')->set('motivo', 'Corregir referencia')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('lotes_produccion', ['codigo' => 'CORREGIDO']);
        $panel->call('runAction', 'anular')->assertHasErrors('motivo');
    }

    public function test_duplicate_sources_negative_liters_and_invalid_molds_are_rejected(): void
    {
        $admin = $this->actor();
        Sanctum::actingAs($admin);
        $input = $this->payload();
        $input['recepciones'][] = $input['recepciones'][0];
        $this->postJson('/api/v1/produccion/lotes', $input)->assertUnprocessable()->assertJsonValidationErrors('recepciones.0.recepcion_id');
        array_pop($input['recepciones']);
        $input['recepciones'][0]['litros'] = '-1';
        $this->postJson('/api/v1/produccion/lotes', $input)->assertUnprocessable();
        $input['recepciones'][0]['litros'] = '100';
        $lot = app(GestionarProduccion::class)->create($admin->id, $input);
        app(GestionarProduccion::class)->start($admin->id, $lot->uuid);
        $this->postJson('/api/v1/produccion/lotes/'.$lot->uuid.'/finalizar', ['moldes' => 'incorrecto'])->assertUnprocessable()->assertJsonValidationErrors('moldes');
    }
}
