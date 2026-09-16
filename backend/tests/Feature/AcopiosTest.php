<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Acopios\GestionarAcopios;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AcopiosTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(string $role = 'administrador', bool $active = true): User
    {
        Role::findOrCreate('administrador', 'web');
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['active' => $active]);
        $user->assignRole($role);

        return $user;
    }

    private function routeWithProducer(User $collector, int $count = 1): array
    {
        $route = RutaAcopio::factory()->create(['recolector_id' => $collector->id]);
        $producers = Productor::factory()->count($count)->create();
        foreach ($producers as $index => $producer) {
            DB::table('ruta_productor')->insert(['ruta_id' => $route->id, 'productor_id' => $producer->id, 'orden' => $index + 1]);
        }

        return [$route, $producers];
    }

    public function test_admin_creates_closes_and_audits_a_jornada(): void
    {
        $admin = $this->user();
        $collector = $this->user('recolector');
        $route = RutaAcopio::factory()->create(['recolector_id' => $collector->id]);
        $this->actingAs($admin);
        Livewire::test('pages::acopios.index')->call('create')->set('form', ['ruta_id' => (string) $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta', 'observaciones' => 'Mañana'])->call('save')->assertHasNoErrors();
        $journey = JornadaAcopio::query()->sole();
        $this->assertSame($collector->id, $journey->recolector_id);
        Livewire::test('pages::acopios.index')->call('show', $journey->id)->call('finish', 'cerrada')->assertHasNoErrors();
        $this->assertSame('cerrada', $journey->fresh()->estado);
        $this->assertDatabaseHas('auditorias_acopio', ['jornada_id' => $journey->id, 'accion' => 'cierre', 'usuario_id' => $admin->id]);
    }

    public function test_duplicate_journey_and_anulation_need_reason(): void
    {
        $admin = $this->user();
        $collector = $this->user('recolector');
        $route = RutaAcopio::factory()->create(['recolector_id' => $collector->id]);
        $this->actingAs($admin);
        app(GestionarAcopios::class)->createJourney($admin->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta']);
        Livewire::test('pages::acopios.index')->call('create')->set('form', ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta', 'observaciones' => ''])->call('save')->assertHasErrors();
        $journey = JornadaAcopio::query()->sole();
        Livewire::test('pages::acopios.index')->call('show', $journey->id)->call('finish', 'anulada')->assertHasErrors('motivo');
        Livewire::test('pages::acopios.index')->call('show', $journey->id)->set('motivo', 'Ruta suspendida')->call('finish', 'anulada')->assertHasNoErrors();
        $this->assertDatabaseHas('auditorias_acopio', ['jornada_id' => $journey->id, 'accion' => 'anulacion']);
    }

    public function test_collector_api_routes_journey_and_sync_is_idempotent(): void
    {
        $collector = $this->user('recolector');
        [$route, $producers] = $this->routeWithProducer($collector);
        $this->actingAs($collector);
        $this->getJson('/api/v1/acopios/rutas')->assertOk()->assertJsonPath('data.0.productores.0.id', $producers[0]->id);
        $response = $this->postJson('/api/v1/acopios/jornadas', ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'])->assertCreated()
            ->assertJsonPath('data.ruta_id', $route->id)->assertJsonPath('data.ruta_codigo', $route->codigo);
        $uuid = $response->json('data.uuid_publico');
        $payload = ['entregas' => [['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => JornadaAcopio::query()->sole()->id, 'productor_id' => $producers[0]->id, 'litros' => '12.345', 'recolectada_at' => '2026-09-09 08:00:00']]];
        $this->postJson('/api/v1/acopios/sincronizar', $payload)->assertOk()->assertJsonCount(1, 'data.creados');
        $this->postJson('/api/v1/acopios/sincronizar', $payload)->assertOk()->assertJsonCount(1, 'data.repetidos')->assertJsonCount(0, 'data.creados');
        $this->getJson('/api/v1/acopios/jornadas/'.$uuid)->assertOk()
            ->assertJsonPath('data.entregas.0.litros', '12.345')
            ->assertJsonPath('data.ruta_id', $route->id)->assertJsonPath('data.ruta_codigo', $route->codigo);
    }

    public function test_api_rejects_foreign_route_and_invalid_batch_elements_without_hiding_errors(): void
    {
        $collector = $this->user('recolector');
        $other = $this->user('recolector');
        [$route, $producers] = $this->routeWithProducer($collector);
        $foreignRoute = RutaAcopio::factory()->create(['recolector_id' => $other->id]);
        $this->actingAs($collector)->postJson('/api/v1/acopios/jornadas', ['ruta_id' => $foreignRoute->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'])->assertForbidden();
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        $this->postJson('/api/v1/acopios/sincronizar', ['entregas' => [['uuid_cliente' => 'bad', 'jornada_id' => $journey['id'], 'productor_id' => 999999, 'litros' => 0, 'recolectada_at' => 'bad']]])->assertOk()->assertJsonCount(1, 'data.rechazados')->assertJsonPath('data.rechazados.0.indice', 0);
    }

    public function test_only_admins_manage_panel_and_closed_journey_cannot_be_changed(): void
    {
        $collector = $this->user('recolector');
        [$route, $producers] = $this->routeWithProducer($collector);
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        $this->actingAs($collector)->get('/admin/acopios')->assertForbidden();
        $admin = $this->user();
        app(GestionarAcopios::class)->changeState($admin->id, $journey['id'], 'cerrada');
        $this->actingAs($collector)->postJson('/api/v1/acopios/sincronizar', ['entregas' => [['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => $journey['id'], 'productor_id' => $producers[0]->id, 'litros' => 1, 'recolectada_at' => now()->toDateTimeString()]]])->assertOk()->assertJsonCount(1, 'data.rechazados');
    }

    public function test_correction_requires_open_journey_and_writes_audit(): void
    {
        $admin = $this->user();
        $collector = $this->user('recolector');
        [$route, $producers] = $this->routeWithProducer($collector);
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        $this->actingAs($collector)->postJson('/api/v1/acopios/sincronizar', ['entregas' => [['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => $journey['id'], 'productor_id' => $producers[0]->id, 'litros' => 2, 'recolectada_at' => now()->toDateTimeString()]]]);
        $delivery = EntregaAcopio::query()->sole();
        $this->actingAs($admin);
        app(GestionarAcopios::class)->correctDelivery($admin->id, $delivery->id, ['litros' => '3.500', 'observacion' => 'Corregido', 'motivo' => 'Lectura validada']);
        $this->assertSame('3.500', (string) $delivery->fresh()->litros);
        $this->assertDatabaseHas('auditorias_acopio', ['entrega_id' => $delivery->id, 'accion' => 'correccion', 'usuario_id' => $admin->id]);
    }

    #[TestWith(['supervisor'])]
    #[TestWith(['contador'])]
    public function test_non_collector_api_cannot_use_field_operations(string $role): void
    {
        $user = $this->user($role);
        $this->actingAs($user)->getJson('/api/v1/acopios/rutas')->assertForbidden();
    }

    public function test_collector_only_lists_own_journeys(): void
    {
        $collector = $this->user('recolector');
        $other = $this->user('recolector');
        [$route] = $this->routeWithProducer($collector);
        [$foreignRoute] = $this->routeWithProducer($other);
        $mine = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        app(GestionarAcopios::class)->createJourney($other->id, ['ruta_id' => $foreignRoute->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);

        $this->actingAs($collector)->getJson('/api/v1/acopios/jornadas')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id'])
            ->assertJsonPath('data.0.ruta_id', $route->id)->assertJsonPath('data.0.ruta_codigo', $route->codigo);
    }

    public function test_collector_journey_filters_estado_ruta_and_fechas(): void
    {
        $collector = $this->user('recolector');
        [$route] = $this->routeWithProducer($collector);
        [$otherRoute] = $this->routeWithProducer($collector);
        $abierta = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        $cerrada = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $otherRoute->id, 'fecha_operativa' => '2026-09-01', 'turno' => 'primera_vuelta'], true);
        $admin = $this->user();
        app(GestionarAcopios::class)->changeState($admin->id, $cerrada['id'], 'cerrada');

        $this->actingAs($collector);
        $this->getJson('/api/v1/acopios/jornadas?estado=abierta')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $abierta['id']);
        $this->getJson('/api/v1/acopios/jornadas?ruta_id='.$otherRoute->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cerrada['id']);
        $this->getJson('/api/v1/acopios/jornadas?desde=2026-09-05&hasta=2026-09-10')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $abierta['id']);
        $this->getJson('/api/v1/acopios/jornadas')->assertOk()->assertJsonPath('data.0.id', $abierta['id'])->assertJsonPath('data.1.id', $cerrada['id']);
    }

    public function test_collector_journeys_paginate(): void
    {
        $collector = $this->user('recolector');
        [$route] = $this->routeWithProducer($collector);
        foreach (range(1, 3) as $day) {
            app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => "2026-09-0{$day}", 'turno' => 'primera_vuelta'], true);
        }

        $response = $this->actingAs($collector)->getJson('/api/v1/acopios/jornadas?per_page=2')->assertOk();
        $response->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3);
    }

    #[TestWith(['supervisor'])]
    #[TestWith(['contador'])]
    public function test_non_collector_cannot_list_journeys(string $role): void
    {
        $user = $this->user($role);
        $this->actingAs($user)->getJson('/api/v1/acopios/jornadas')->assertForbidden();
    }

    public function test_closing_journey_marks_missing_producers_as_no_entrego_and_returns_totals(): void
    {
        $collector = $this->user('recolector');
        [$route, $producers] = $this->routeWithProducer($collector, 3);
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        $this->actingAs($collector)->postJson('/api/v1/acopios/sincronizar', ['entregas' => [['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => $journey['id'], 'productor_id' => $producers[0]->id, 'litros' => '5.500', 'recolectada_at' => '2026-09-09 08:00:00']]])->assertOk();

        $response = $this->postJson('/api/v1/acopios/jornadas/'.$journey['uuid_publico'].'/cerrar')->assertOk();
        $response->assertJsonPath('data.estado', 'cerrada')->assertJsonPath('data.no_entregaron', 2)->assertJsonPath('data.productores_atendidos', 1)->assertJsonPath('data.litros', '5.5');
        $this->assertNotNull(JornadaAcopio::query()->findOrFail($journey['id'])->cerrada_at);
        $this->assertDatabaseCount('entregas_acopio', 3);
        $this->assertDatabaseHas('entregas_acopio', ['jornada_id' => $journey['id'], 'productor_id' => $producers[1]->id, 'no_entrego' => true, 'litros' => null]);
        $this->assertDatabaseHas('entregas_acopio', ['jornada_id' => $journey['id'], 'productor_id' => $producers[2]->id, 'no_entrego' => true, 'litros' => null]);
        $this->assertDatabaseHas('auditorias_acopio', ['jornada_id' => $journey['id'], 'accion' => 'cierre', 'usuario_id' => $collector->id]);
    }

    public function test_closing_journey_ignores_inactive_producers_of_the_route(): void
    {
        $collector = $this->user('recolector');
        [$route, $producers] = $this->routeWithProducer($collector, 2);
        $producers[1]->update(['estado' => false]);
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);

        $this->actingAs($collector)->postJson('/api/v1/acopios/jornadas/'.$journey['uuid_publico'].'/cerrar')->assertOk()->assertJsonPath('data.no_entregaron', 1);
        $this->assertDatabaseMissing('entregas_acopio', ['jornada_id' => $journey['id'], 'productor_id' => $producers[1]->id]);
    }

    public function test_collector_cannot_close_a_foreign_journey(): void
    {
        $owner = $this->user('recolector');
        $other = $this->user('recolector');
        [$route] = $this->routeWithProducer($owner);
        $journey = app(GestionarAcopios::class)->createJourney($owner->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);

        $this->actingAs($other)->postJson('/api/v1/acopios/jornadas/'.$journey['uuid_publico'].'/cerrar')->assertForbidden();
        $this->assertSame('abierta', JornadaAcopio::query()->findOrFail($journey['id'])->estado);
    }

    public function test_already_closed_journey_is_rejected_with_clear_message(): void
    {
        $collector = $this->user('recolector');
        [$route] = $this->routeWithProducer($collector);
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        $this->actingAs($collector)->postJson('/api/v1/acopios/jornadas/'.$journey['uuid_publico'].'/cerrar')->assertOk();

        $this->postJson('/api/v1/acopios/jornadas/'.$journey['uuid_publico'].'/cerrar')->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->assertDatabaseCount('auditorias_acopio', 1);
    }

    public function test_annulled_journey_cannot_be_closed_by_collector(): void
    {
        $collector = $this->user('recolector');
        $admin = $this->user();
        [$route] = $this->routeWithProducer($collector);
        $journey = app(GestionarAcopios::class)->createJourney($collector->id, ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'], true);
        app(GestionarAcopios::class)->changeState($admin->id, $journey['id'], 'anulada', 'Ruta suspendida por lluvia');

        $this->actingAs($collector)->postJson('/api/v1/acopios/jornadas/'.$journey['uuid_publico'].'/cerrar')->assertUnprocessable()->assertJsonValidationErrors('estado');
    }
}
