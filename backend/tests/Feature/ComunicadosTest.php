<?php

namespace Tests\Feature;

use App\Application\Comunicados\GestionarComunicados;
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

class ComunicadosTest extends TestCase
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
        Role::findOrCreate('recolector', 'web');

        return ['uuid' => (string) Str::uuid(), 'titulo' => 'Información de la planta', 'contenido' => 'Texto privado', 'tipo' => 'general', 'audiencia' => 'roles', 'roles' => ['recolector'], 'publicar_at' => now()->toDateTimeString()];
    }

    public function test_only_target_roles_receive_published_content_without_recipient_data(): void
    {
        $a = $this->actor();
        $collector = $this->actor('recolector');
        $quality = $this->actor('calidad');
        $s = app(GestionarComunicados::class);
        $r = $s->save($a->id, $this->payload());
        Sanctum::actingAs($collector);
        $this->getJson('/api/v1/comunicados')->assertOk()->assertJsonCount(0, 'data');
        $s->publish($a->id, $r->uuid);
        $response = $this->getJson('/api/v1/comunicados')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.titulo', 'Información de la planta');
        $this->assertArrayNotHasKey('roles_destino', $response->json('data.0'));
        $this->assertArrayNotHasKey('productores', $response->json('data.0'));
        Sanctum::actingAs($quality);
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertNotFound();
        $this->postJson('/api/v1/comunicados/'.$r->uuid.'/lectura')->assertNotFound();
    }

    public function test_reads_are_idempotent_and_do_not_expose_other_users(): void
    {
        $a = $this->actor();
        $u = $this->actor('recolector');
        $s = app(GestionarComunicados::class);
        $r = $s->save($a->id, $this->payload());
        $s->publish($a->id, $r->uuid);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/comunicados/'.$r->uuid.'/lectura')->assertOk();
        $this->postJson('/api/v1/comunicados/'.$r->uuid.'/lectura')->assertOk();
        $this->assertDatabaseCount('lecturas_comunicado', 1);
        $response = $this->getJson('/api/v1/comunicados')->assertOk();
        $this->assertNotNull($response->json('data.0.leida_at'));
        $this->assertArrayNotHasKey('lecturas', $response->json('data.0'));
    }

    public function test_scheduling_and_expiry_work_without_scheduler_and_are_audited(): void
    {
        $this->freezeTime();
        $a = $this->actor();
        $u = $this->actor('recolector');
        $input = $this->payload();
        $input['publicar_at'] = now()->addHour()->toDateTimeString();
        $input['vence_at'] = now()->addHours(2)->toDateTimeString();
        $s = app(GestionarComunicados::class);
        $r = $s->save($a->id, $input);
        $this->assertSame('programado', $s->publish($a->id, $r->uuid)->estado);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertNotFound();
        $this->travel(60)->minutes();
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertOk()->assertJsonPath('data.estado', 'publicado');
        $this->artisan('comunicados:actualizar')->assertSuccessful();
        $this->assertSame('publicado', $r->fresh()->estado);
        $this->travel(60)->minutes();
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertNotFound();
        $this->artisan('comunicados:actualizar')->assertSuccessful();
        $this->assertSame('vencido', $r->fresh()->estado);
        $this->assertDatabaseCount('comunicados', 1);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'programacion_automatica']);
    }

    public function test_producer_identity_and_route_audience_remain_scoped(): void
    {
        $a = $this->actor();
        $u = $this->actor('productor');
        $other = $this->actor('productor');
        $p = Productor::factory()->create(['estado' => true]);
        $p2 = Productor::factory()->create(['estado' => true]);
        $route = RutaAcopio::factory()->create(['estado' => true]);
        DB::table('ruta_productor')->insert(['ruta_id' => $route->id, 'productor_id' => $p->id, 'orden' => 1]);
        $s = app(GestionarComunicados::class);
        $s->link($a->id, ['usuario_id' => $u->id, 'productor_id' => $p->id, 'activa' => true, 'motivo' => 'Identidad verificada']);
        $s->link($a->id, ['usuario_id' => $other->id, 'productor_id' => $p2->id, 'activa' => true, 'motivo' => 'Identidad verificada']);
        $input = $this->payload();
        $input['audiencia'] = 'ruta';
        $input['ruta_id'] = $route->id;
        $r = $s->save($a->id, $input);
        $s->publish($a->id, $r->uuid);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertOk();
        $this->getJson('/api/v1/productores')->assertForbidden();
        $this->postJson('/api/v1/productores', [])->assertForbidden();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertNotFound();
        DB::table('ruta_productor')->where('productor_id', $p->id)->update(['ruta_id' => RutaAcopio::factory()->create()->id]);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertOk();
        $s->link($a->id, ['usuario_id' => $u->id, 'productor_id' => $p->id, 'activa' => false, 'motivo' => 'Acceso suspendido']);
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertNotFound();
    }

    public function test_inactive_producers_are_excluded_and_links_cannot_be_reassigned(): void
    {
        $a = $this->actor();
        Sanctum::actingAs($a);
        $u = $this->actor('productor');
        $p = Productor::factory()->create(['estado' => true]);
        $s = app(GestionarComunicados::class);
        $s->link($a->id, ['usuario_id' => $u->id, 'productor_id' => $p->id, 'activa' => true, 'motivo' => 'Verificación']);
        $this->postJson('/api/v1/comunicados/cuentas/vincular', ['usuario_id' => $u->id, 'productor_id' => Productor::factory()->create(['estado' => true])->id, 'activa' => true, 'motivo' => 'Cambio'])->assertUnprocessable()->assertJsonValidationErrors('cuenta');
        $input = $this->payload();
        $input['audiencia'] = 'seleccionados';
        $input['productores'] = [$p->id];
        $r = $s->save($a->id, $input);
        $s->publish($a->id, $r->uuid);
        $p->update(['estado' => false]);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/comunicados/'.$r->uuid)->assertNotFound();
        $this->assertDatabaseHas('comunicado_productor', ['productor_id' => $p->id]);
    }

    public function test_annulment_and_draft_correction_require_reason_and_keep_history(): void
    {
        $a = $this->actor();
        Sanctum::actingAs($a);
        $input = $this->payload();
        $r = $this->postJson('/api/v1/comunicados', $input)->assertCreated();
        $uuid = $r->json('data.uuid');
        $this->postJson('/api/v1/comunicados', $input)->assertOk();
        $input['titulo'] = 'Título corregido';
        $this->putJson('/api/v1/comunicados/'.$uuid, $input)->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $input['motivo'] = 'Corrección tipográfica';
        $this->putJson('/api/v1/comunicados/'.$uuid, $input)->assertOk();
        $this->postJson('/api/v1/comunicados/'.$uuid.'/publicar')->assertOk();
        $this->putJson('/api/v1/comunicados/'.$uuid, $input)->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->postJson('/api/v1/comunicados/'.$uuid.'/anular')->assertUnprocessable();
        $this->postJson('/api/v1/comunicados/'.$uuid.'/anular', ['motivo' => 'Información sustituida'])->assertOk();
        $this->assertDatabaseCount('comunicados', 1);
        $this->assertDatabaseHas('auditorias_operativas', ['modulo' => 'comunicados', 'accion' => 'correccion', 'motivo' => 'Corrección tipográfica']);
        $this->assertDatabaseHas('auditorias_operativas', ['modulo' => 'comunicados', 'accion' => 'anulacion', 'motivo' => 'Información sustituida']);
    }

    #[TestWith(['recolector'])] #[TestWith(['calidad'])] #[TestWith(['contador'])] #[TestWith(['supervisor'])] #[TestWith(['productor'])]
    public function test_other_roles_cannot_publish_or_manage(string $role): void
    {
        $u = $this->actor($role);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/comunicados', $this->payload())->assertForbidden();
        $this->actingAs($u)->get('/admin/comunicados')->assertForbidden();
    }

    public function test_guest_and_invalid_audiences_or_dates_are_rejected(): void
    {
        $this->getJson('/api/v1/comunicados')->assertUnauthorized();
        Sanctum::actingAs($this->actor());
        $input = $this->payload();
        $input['roles'] = ['inventado'];
        $this->postJson('/api/v1/comunicados', $input)->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $input['roles'] = ['recolector'];
        $input['vence_at'] = now()->subDay()->toDateTimeString();
        $this->postJson('/api/v1/comunicados', $input)->assertUnprocessable()->assertJsonValidationErrors('vence_at');
    }

    public function test_panel_recovers_after_errors_and_escapes_content(): void
    {
        $a = $this->actor();
        $this->actor('recolector');
        $this->actingAs($a);
        $input = $this->payload();
        $input['contenido'] = '<script>alert(1)</script>';
        Livewire::test('pages::comunicados.index')->call('create')->call('save')->assertHasErrors('titulo')->assertSet('modal', true)->set('form', $input)->call('save')->assertHasNoErrors()->assertSet('modal', false)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->call('edit')->set('form.titulo', 'Corregido')->set('form.motivo', 'Título corregido')->call('save')->assertHasNoErrors();
    }
}
