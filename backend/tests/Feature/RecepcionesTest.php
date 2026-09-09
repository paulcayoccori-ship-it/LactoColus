<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Acopios\GestionarAcopios;
use App\Application\Recepciones\GestionarRecepciones;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Recepciones\AlertaConciliacion;
use App\Infrastructure\Recepciones\RecepcionPlanta;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RecepcionesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(string $role = 'administrador', bool $active = true): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['active' => $active]);
        $user->assignRole($role);

        return $user;
    }

    private function closedJourney(): array
    {
        $admin = $this->user();
        $collector = $this->user('recolector');
        $route = RutaAcopio::factory()->create(['recolector_id' => $collector->id]);
        $producer = Productor::factory()->create();
        DB::table('ruta_productor')->insert(['ruta_id' => $route->id, 'productor_id' => $producer->id, 'orden' => 1]);
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        $this->actingAs($admin);
        app(GestionarAcopios::class)->changeState($admin->id, $journey['id'], 'cerrada');
        EntregaAcopio::create(['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => $journey['id'], 'ruta_id' => $route->id, 'productor_id' => $producer->id, 'recolector_id' => $collector->id, 'litros' => '10.000', 'recolectada_at' => '2026-09-09 08:00:00', 'sincronizada_at' => now()]);

        return [$admin, $collector, JornadaAcopio::findOrFail($journey['id']), $route, $producer];
    }

    public function test_reception_calculates_field_liters_and_alerts_both_directions(): void
    {
        [$admin, , $journey] = $this->closedJourney();
        $this->actingAs($admin);
        $service = app(GestionarRecepciones::class);
        $service->saveTolerance($admin->id, '5');
        $within = $service->create($admin->id, ['jornada_id' => $journey->id, 'litros_planta' => '10.400', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'manual']);
        $this->assertSame('dentro_tolerancia', $within['recepcion']['resultado']);
        $this->assertSame('10.000', $within['recepcion']['litros_campo']);
        $journey2 = JornadaAcopio::factory()->create(['ruta_id' => $journey->ruta_id, 'recolector_id' => $journey->recolector_id, 'fecha_operativa' => '2026-09-10', 'turno' => 'primera_vuelta', 'estado' => 'cerrada']);
        EntregaAcopio::create(['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => $journey2->id, 'ruta_id' => $journey2->ruta_id, 'productor_id' => $journey->entregas()->first()->productor_id, 'recolector_id' => $journey->recolector_id, 'litros' => '10.000', 'recolectada_at' => now(), 'sincronizada_at' => now()]);
        $outside = $service->create($admin->id, ['jornada_id' => $journey2->id, 'litros_planta' => '8.000', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'sensor']);
        $this->assertSame('con_diferencia', $outside['recepcion']['resultado']);
        $this->assertDatabaseHas('alertas_conciliacion', ['recepcion_id' => $outside['recepcion']['id'], 'estado' => 'pendiente']);
    }

    public function test_requires_defined_tolerance_and_closed_journey_positive_liters(): void
    {
        [$admin, , $journey] = $this->closedJourney();
        $service = app(GestionarRecepciones::class);
        $this->expectException(ValidationException::class);
        $service->create($admin->id, ['jornada_id' => $journey->id, 'litros_planta' => '10', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'manual']);
    }

    #[TestWith(['abierta'])]
    #[TestWith(['anulada'])]
    public function test_rejects_open_or_annulled_journey(string $state): void
    {
        [$admin, , $journey] = $this->closedJourney();
        $journey->update(['estado' => $state]);
        app(GestionarRecepciones::class)->saveTolerance($admin->id, '2');
        $this->expectException(ValidationException::class);
        app(GestionarRecepciones::class)->create($admin->id, ['jornada_id' => $journey->id, 'litros_planta' => '10', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'manual']);
    }

    public function test_external_uuid_is_idempotent_and_duplicates_are_not_created(): void
    {
        [$admin, , $journey] = $this->closedJourney();
        $this->actingAs($admin);
        app(GestionarRecepciones::class)->saveTolerance($admin->id, '5');
        $uuid = (string) Str::uuid();
        $payload = ['jornada_uuid' => $journey->uuid_publico, 'uuid_lectura_externa' => $uuid, 'litros_planta' => '10', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'manual'];
        $this->postJson('/api/v1/recepciones/lecturas', $payload)->assertCreated()->assertJsonPath('data.estado_sincronizacion', 'creada');
        $this->postJson('/api/v1/recepciones/lecturas', $payload)->assertOk()->assertJsonPath('data.estado_sincronizacion', 'repetida');
        $this->assertDatabaseCount('recepciones_planta', 1);
    }

    public function test_correction_recalculates_and_audits_and_anulation_is_audited(): void
    {
        [$admin, , $journey] = $this->closedJourney();
        $service = app(GestionarRecepciones::class);
        $service->saveTolerance($admin->id, '5');
        $reception = $service->create($admin->id, ['jornada_id' => $journey->id, 'litros_planta' => '8', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'manual'])['recepcion'];
        $service->correct($admin->id, $reception['id'], ['litros_planta' => '10', 'motivo' => 'Lectura revisada']);
        $this->assertSame('dentro_tolerancia', RecepcionPlanta::find($reception['id'])->resultado);
        $this->assertDatabaseHas('auditorias_recepcion', ['recepcion_id' => $reception['id'], 'accion' => 'correccion']);
        $service->annul($admin->id, $reception['id'], 'Recepción invalidada');
        $this->assertDatabaseHas('auditorias_recepcion', ['recepcion_id' => $reception['id'], 'accion' => 'anulacion']);
    }

    public function test_alert_resolution_requires_comment_and_stores_user(): void
    {
        [$admin, , $journey] = $this->closedJourney();
        $service = app(GestionarRecepciones::class);
        $service->saveTolerance($admin->id, '1');
        $reception = $service->create($admin->id, ['jornada_id' => $journey->id, 'litros_planta' => '12', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'manual'])['recepcion'];
        $alert = AlertaConciliacion::query()->where('recepcion_id', $reception['id'])->sole();
        $this->expectException(ValidationException::class);
        $service->resolveAlert($admin->id, $alert->id, '');
    }

    public function test_only_admins_can_manage_receptions_and_dashboard_counts_non_annulled(): void
    {
        [$admin, $collector, $journey] = $this->closedJourney();
        $this->actingAs($collector)->get('/admin/recepciones')->assertForbidden();
        $this->actingAs($admin);
        app(GestionarRecepciones::class)->saveTolerance($admin->id, '5');
        app(GestionarRecepciones::class)->create($admin->id, ['jornada_id' => $journey->id, 'litros_planta' => '10', 'recibida_at' => now()->toDateTimeString(), 'fuente_medicion' => 'manual']);
        Livewire::test('pages::dashboard')->assertViewHas('recepciones_hoy', 1)->assertViewHas('litros_recibidos_hoy', '10.000');
    }

    public function test_panel_recovers_after_tolerance_validation_error(): void
    {
        $admin = $this->user();
        $this->actingAs($admin);
        Livewire::test('pages::recepciones.index')->set('tolerancia', '-1')->call('saveTolerance')->assertHasErrors('valor')->assertSee('Guardar tolerancia');
    }
}
