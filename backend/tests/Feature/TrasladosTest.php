<?php

namespace Tests\Feature;

use App\Application\Comunicados\GestionarComunicados;
use App\Application\Traslados\GestionarTraslados;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrasladosTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'administrador'): User
    {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(['active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function payload(): array
    {
        $p = Productor::factory()->create(['estado' => true]);
        $route = RutaAcopio::factory()->create(['estado' => true]);
        DB::table('ruta_productor')->insert(['productor_id' => $p->id, 'ruta_id' => $route->id, 'orden' => 1]);

        return ['uuid' => (string) Str::uuid(), 'productor_id' => $p->id, 'ruta_solicitada_id' => RutaAcopio::factory()->create(['estado' => true])->id, 'fecha_efectiva' => today()->addDays(3)->toDateString(), 'motivo' => 'Cambio de domicilio'];
    }

    private function configured(User $admin): GestionarTraslados
    {
        $s = app(GestionarTraslados::class);
        $s->configuration($admin->id, ['anticipacion_dias' => 3, 'motivo' => 'Anticipación aprobada']);

        return $s;
    }

    public function test_requires_configuration_and_minimum_calendar_days(): void
    {
        $this->freezeTime();
        $a = $this->actor();
        Sanctum::actingAs($a);
        $input = $this->payload();
        $this->postJson('/api/v1/traslados', $input)->assertUnprocessable()->assertJsonValidationErrors('configuracion');
        $this->configured($a);
        $input['fecha_efectiva'] = today()->addDays(2)->toDateString();
        $this->postJson('/api/v1/traslados', $input)->assertUnprocessable()->assertJsonValidationErrors('fecha_efectiva');
        $input['fecha_efectiva'] = today()->addDays(3)->toDateString();
        $this->postJson('/api/v1/traslados', $input)->assertCreated()->assertJsonPath('data.regla_aplicada.valores.anticipacion_dias', 3);
    }

    public function test_approval_does_not_move_and_application_compacts_order_once(): void
    {
        $this->freezeTime();
        $a = $this->actor();
        $s = $this->configured($a);
        $input = $this->payload();
        $old = DB::table('ruta_productor')->where('productor_id', $input['productor_id'])->value('ruta_id');
        $other = Productor::factory()->create();
        DB::table('ruta_productor')->insert(['productor_id' => $other->id, 'ruta_id' => $old, 'orden' => 2]);
        $targetOther = Productor::factory()->create();
        DB::table('ruta_productor')->insert(['productor_id' => $targetOther->id, 'ruta_id' => $input['ruta_solicitada_id'], 'orden' => 1]);
        $r = $s->request($a->id, $input);
        $s->decide($a->id, $r->uuid, true, 'Traslado autorizado');
        $this->assertDatabaseHas('ruta_productor', ['productor_id' => $input['productor_id'], 'ruta_id' => $old]);
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/traslados/'.$r->uuid.'/aplicar')->assertUnprocessable();
        $this->travel(3)->days();
        $s->apply($a->id, $r->uuid);
        $s->apply($a->id, $r->uuid);
        $this->assertDatabaseHas('ruta_productor', ['productor_id' => $input['productor_id'], 'ruta_id' => $input['ruta_solicitada_id'], 'orden' => 2]);
        $this->assertDatabaseHas('ruta_productor', ['productor_id' => $other->id, 'ruta_id' => $old, 'orden' => 1]);
        $this->assertDatabaseCount('historial_traslados', 1);
        $this->assertDatabaseHas('historial_traslados', ['ruta_anterior_id' => $old, 'ruta_nueva_id' => $input['ruta_solicitada_id'], 'orden_anterior' => 1, 'orden_nuevo' => 2]);
        $this->assertSame('aplicada', $r->fresh()->estado);
    }

    public function test_duplicate_open_requests_are_rejected_but_uuid_retries_succeed(): void
    {
        $a = $this->actor();
        $this->configured($a);
        Sanctum::actingAs($a);
        $input = $this->payload();
        $this->postJson('/api/v1/traslados', $input)->assertCreated();
        $this->postJson('/api/v1/traslados', $input)->assertOk();
        $input['uuid'] = (string) Str::uuid();
        $this->postJson('/api/v1/traslados', $input)->assertUnprocessable()->assertJsonValidationErrors('conflicto');
        $this->assertDatabaseCount('solicitudes_traslado', 1);
    }

    public function test_changed_assignment_stops_scheduled_application_and_records_conflict(): void
    {
        $this->freezeTime();
        $a = $this->actor();
        $s = $this->configured($a);
        $input = $this->payload();
        $r = $s->request($a->id, $input);
        $s->decide($a->id, $r->uuid, true, 'Autorizado');
        $other = RutaAcopio::factory()->create();
        DB::table('ruta_productor')->where('productor_id', $input['productor_id'])->update(['ruta_id' => $other->id]);
        $this->travel(3)->days();
        $this->artisan('traslados:aplicar')->assertSuccessful();
        $this->assertSame('aprobada', $r->fresh()->estado);
        $this->assertNotNull($r->fresh()->ultimo_error);
        $this->assertDatabaseHas('ruta_productor', ['productor_id' => $input['productor_id'], 'ruta_id' => $other->id]);
        $this->assertDatabaseCount('historial_traslados', 0);
        $this->assertDatabaseHas('auditorias_operativas', ['modulo' => 'traslados', 'accion' => 'conflicto_automatico']);
    }

    public function test_configuration_changes_preserve_existing_snapshot_and_scheduler_applies(): void
    {
        $this->freezeTime();
        $a = $this->actor();
        $s = $this->configured($a);
        $r = $s->request($a->id, $this->payload());
        $s->configuration($a->id, ['anticipacion_dias' => 10, 'motivo' => 'Nuevo plazo']);
        $this->assertSame(3, $r->fresh()->regla_aplicada['valores']['anticipacion_dias']);
        $s->decide($a->id, $r->uuid, true, 'Aprobado con plazo anterior');
        $this->travel(4)->days();
        $this->artisan('traslados:aplicar')->assertSuccessful();
        $this->artisan('traslados:aplicar')->assertSuccessful();
        $this->assertSame('aplicada', $r->fresh()->estado);
        $this->assertDatabaseCount('historial_traslados', 1);
    }

    public function test_rejection_and_cancellation_keep_reason_and_release_pending_slot(): void
    {
        $a = $this->actor();
        $s = $this->configured($a);
        $input = $this->payload();
        $r = $s->request($a->id, $input);
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/traslados/'.$r->uuid.'/rechazar')->assertUnprocessable();
        $s->decide($a->id, $r->uuid, false, 'Ruta sin disponibilidad');
        $this->assertDatabaseHas('solicitudes_traslado', ['uuid' => $r->uuid, 'estado' => 'rechazada', 'comentario' => 'Ruta sin disponibilidad', 'decidida_por' => $a->id]);
        $input['uuid'] = (string) Str::uuid();
        $next = $s->request($a->id, $input);
        $s->cancel($a->id, $next->uuid, 'Cambio desistido');
        $this->assertDatabaseCount('solicitudes_traslado', 2);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'cancelada', 'motivo' => 'Cambio desistido']);
    }

    public function test_producers_only_see_and_cancel_own_requests_and_cannot_approve(): void
    {
        $a = $this->actor();
        $s = $this->configured($a);
        $u = $this->actor('productor');
        $other = $this->actor('productor');
        $input = $this->payload();
        $input2 = $this->payload();
        $links = app(GestionarComunicados::class);
        $links->link($a->id, ['usuario_id' => $u->id, 'productor_id' => $input['productor_id'], 'activa' => true, 'motivo' => 'Identidad verificada']);
        $links->link($a->id, ['usuario_id' => $other->id, 'productor_id' => $input2['productor_id'], 'activa' => true, 'motivo' => 'Identidad verificada']);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/traslados', $input2)->assertForbidden();
        $response = $this->postJson('/api/v1/traslados', $input)->assertCreated();
        $uuid = $response->json('data.uuid');
        $this->postJson('/api/v1/traslados/'.$uuid.'/aprobar', ['comentario' => 'Autoaprobado'])->assertForbidden();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/traslados/'.$uuid)->assertNotFound();
        $this->postJson('/api/v1/traslados/'.$uuid.'/cancelar', ['motivo' => 'Ajeno'])->assertNotFound();
        $this->getJson('/api/v1/traslados')->assertJsonCount(0, 'data');
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/traslados/'.$uuid.'/cancelar', ['motivo' => 'Desistimiento'])->assertOk();
    }

    #[TestWith(['recolector'])] #[TestWith(['calidad'])] #[TestWith(['contador'])] #[TestWith(['supervisor'])]
    public function test_other_roles_cannot_access_transfers(string $role): void
    {
        $u = $this->actor($role);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/traslados')->assertForbidden();
        $this->postJson('/api/v1/traslados', [])->assertForbidden();
        $this->actingAs($u)->get('/admin/traslados')->assertForbidden();
    }

    public function test_invalid_route_producer_and_empty_fields_are_rejected(): void
    {
        $a = $this->actor();
        $this->configured($a);
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/traslados', [])->assertUnprocessable()->assertJsonValidationErrors(['uuid', 'productor_id', 'motivo', 'fecha_efectiva']);
        $input = $this->payload();
        $input['ruta_solicitada_id'] = DB::table('ruta_productor')->where('productor_id', $input['productor_id'])->value('ruta_id');
        $this->postJson('/api/v1/traslados', $input)->assertUnprocessable()->assertJsonValidationErrors('ruta_solicitada_id');
        Productor::find($input['productor_id'])->update(['estado' => false]);
        $this->postJson('/api/v1/traslados', $input)->assertUnprocessable()->assertJsonValidationErrors('productor_id');
    }

    public function test_livewire_recovers_and_requires_comment_for_approval(): void
    {
        $a = $this->actor();
        $this->configured($a);
        $this->actingAs($a);
        $input = $this->payload();
        Livewire::test('pages::traslados.index')->call('create')->call('save')->assertHasErrors('productor_id')->assertSet('modal', true)->set('form', $input)->call('save')->assertHasNoErrors()->assertSet('modal', false)->call('runAction', 'aprobar')->assertHasErrors('motivo')->set('comment', 'Autorizado')->call('runAction', 'aprobar')->assertHasNoErrors()->assertSee('aprobada');
    }
}
