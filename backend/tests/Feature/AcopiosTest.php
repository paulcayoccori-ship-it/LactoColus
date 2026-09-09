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
        $response = $this->postJson('/api/v1/acopios/jornadas', ['ruta_id' => $route->id, 'fecha_operativa' => '2026-09-09', 'turno' => 'primera_vuelta'])->assertCreated();
        $uuid = $response->json('data.uuid_publico');
        $payload = ['entregas' => [['uuid_cliente' => (string) Str::uuid(), 'jornada_id' => JornadaAcopio::query()->sole()->id, 'productor_id' => $producers[0]->id, 'litros' => '12.345', 'recolectada_at' => '2026-09-09 08:00:00']]];
        $this->postJson('/api/v1/acopios/sincronizar', $payload)->assertOk()->assertJsonCount(1, 'data.creados');
        $this->postJson('/api/v1/acopios/sincronizar', $payload)->assertOk()->assertJsonCount(1, 'data.repetidos')->assertJsonCount(0, 'data.creados');
        $this->getJson('/api/v1/acopios/jornadas/'.$uuid)->assertOk()->assertJsonPath('data.entregas.0.litros', '12.345');
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
}
