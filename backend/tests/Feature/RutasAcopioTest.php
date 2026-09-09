<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Rutas\GestionarRutas;
use App\Application\Usuarios\GuardarUsuario;
use App\Domain\Rutas\RutaRepository;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RutasAcopioTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(string $role = 'administrador', bool $active = true): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['active' => $active]);
        $user->assignRole($role);

        return $user;
    }

    private function rutasComponent(): mixed
    {
        return Livewire::actingAs($this->user())->test('pages::rutas.index');
    }

    public function test_crud_validation_recovery_and_real_dashboard_count(): void
    {
        $component = $this->rutasComponent()->assertSee('No se encontraron rutas')->call('create')->call('save')
            ->assertHasErrors(['form.codigo', 'form.nombre'])->assertSet('modal', true)->assertSee('Guardar');
        $component->set('form', ['codigo' => ' R-01 ', 'nombre' => ' Ruta Norte ', 'descripcion' => 'Visitar comunidad', 'estado' => true, 'recolector_id' => 999])
            ->call('save')->assertHasNoErrors()->assertSet('modal', false)->assertSee('Sin recolector asignado');
        $ruta = RutaAcopio::query()->sole();
        $this->assertSame('R-01', $ruta->codigo);
        $this->assertNull($ruta->recolector_id);
        $component->call('open', $ruta->id)->set('form.nombre', '<script>alert(1)</script>')->call('save')->assertHasNoErrors()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $component->call('changeState', $ruta->id, false)->assertHasNoErrors();
        $this->assertFalse($ruta->fresh()->estado);
        Livewire::test('pages::dashboard')->assertViewHas('rutas_activas', 0);
        $component->call('changeState', $ruta->id, true);
        Livewire::test('pages::dashboard')->assertViewHas('rutas_activas', 1);
        $this->assertDatabaseCount('rutas_acopio', 1);
        $this->get('/admin/rutas')->assertSee('Rutas de acopio');
        $this->delete('/admin/rutas/'.$ruta->id)->assertNotFound();
    }

    #[TestWith(['codigo', '', 'form.codigo'])]
    #[TestWith(['codigo', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'form.codigo'])]
    #[TestWith(['nombre', '', 'form.nombre'])]
    #[TestWith(['descripcion', [], 'form.descripcion'])]
    #[TestWith(['estado', 'otro', 'form.estado'])]
    public function test_rejects_invalid_fields(string $field, mixed $value, string $error): void
    {
        $this->rutasComponent()->call('create')->set('form', ['codigo' => 'R1', 'nombre' => 'Norte', 'descripcion' => null, 'estado' => true])->set('form.'.$field, $value)->call('save')->assertHasErrors($error);
        $this->assertDatabaseCount('rutas_acopio', 0);
    }

    public function test_unique_code_and_length_limits(): void
    {
        $ruta = RutaAcopio::factory()->create();
        $c = $this->rutasComponent()->call('create')->set('form', ['codigo' => $ruta->codigo, 'nombre' => str_repeat('n', 151), 'descripcion' => str_repeat('d', 5001), 'estado' => true])->call('save')->assertHasErrors(['form.codigo', 'form.nombre', 'form.descripcion']);
        $c->call('open', $ruta->id)->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('rutas_acopio', 1);
    }

    public function test_search_state_and_pagination(): void
    {
        RutaAcopio::factory()->count(16)->create(['nombre' => 'Norte', 'estado' => false]);
        RutaAcopio::factory()->create(['nombre' => 'Sur']);
        $c = $this->rutasComponent()->set('search', 'Norte')->set('estado', '0')->assertViewHas('rutas', fn ($rows) => $rows->total() === 16 && $rows->count() === 15);
        $c->call('setPage', 2)->assertViewHas('rutas', fn ($rows) => $rows->count() === 1)->set('estado', '1')->assertSet('paginators.page', 1)->assertSee('No se encontraron rutas');
        $c->set('search', RutaAcopio::query()->where('nombre', 'Sur')->value('codigo'))->assertViewHas('rutas', fn ($rows) => $rows->total() === 1);
    }

    #[TestWith(['supervisor'])]
    #[TestWith(['recolector'])]
    #[TestWith(['contador'])]
    public function test_only_admins_can_access(string $role): void
    {
        $this->get('/admin/rutas')->assertRedirect('/login');
        $user = $this->user($role);
        $this->actingAs($user)->get('/admin/rutas')->assertForbidden();
        Livewire::actingAs($user)->test('pages::rutas.index')->assertForbidden();
    }

    #[TestWith(['create', []])]
    #[TestWith(['open', [1]])]
    #[TestWith(['show', [1]])]
    #[TestWith(['save', []])]
    #[TestWith(['changeState', [1, false]])]
    #[TestWith(['assignCollector', []])]
    #[TestWith(['removeCollector', []])]
    #[TestWith(['addProducer', []])]
    #[TestWith(['removeProducer', [1]])]
    #[TestWith(['moveProducer', [1, 1]])]
    #[TestWith(['closeDetail', []])]
    public function test_every_action_rechecks_revoked_administrator(string $action, array $args): void
    {
        $admin = $this->user();
        $c = Livewire::actingAs($admin)->test('pages::rutas.index');
        $admin->removeRole('administrador');
        $c->call($action, ...$args)->assertForbidden();
        $this->assertDatabaseCount('rutas_acopio', 0);
    }

    public function test_assign_replace_remove_collectors_and_preserve_invalid_reference(): void
    {
        $ruta = RutaAcopio::factory()->create();
        $other = RutaAcopio::factory()->create();
        $c = $this->rutasComponent()->call('show', $ruta->id)->assertSee('No hay recolectores activos disponibles');
        $first = $this->user('recolector');
        $second = $this->user('recolector');
        $inactive = $this->user('recolector', false);
        $wrong = $this->user('contador');
        foreach ([$inactive->id, $wrong->id, 9999] as $id) {
            $c->set('recolectorId', (string) $id)->call('assignCollector')->assertHasErrors('form.recolector_id');
        }
        $c->set('recolectorId', (string) $first->id)->call('assignCollector')->assertHasNoErrors();
        app(GestionarRutas::class)->collector($other->id, $first->id);
        $this->assertSame($first->id, $other->fresh()->recolector_id);
        $c->set('recolectorId', (string) $second->id)->call('assignCollector')->assertHasNoErrors();
        app(GuardarUsuario::class)->changeState(auth()->id(), $second->id, false);
        $c->call('show', $ruta->id)->assertSee('El responsable está inactivo o perdió el rol recolector')->assertViewHas('recolectores', fn ($rows) => ! in_array($second->id, array_column($rows, 'id')));
        $this->assertSame($second->id, $ruta->fresh()->recolector_id);
        app(GuardarUsuario::class)->handle(auth()->id(), $second->id, ['name' => $second->name, 'email' => $second->email, 'active' => true, 'roles' => ['contador']]);
        $c->call('show', $ruta->id)->assertSee('El responsable está inactivo o perdió el rol recolector')->call('removeCollector')->assertHasNoErrors();
        $this->assertNull($ruta->fresh()->recolector_id);
    }

    public function test_assignments_conflicts_order_and_preservation_when_deactivated_or_deleted(): void
    {
        $ruta = RutaAcopio::factory()->create();
        $other = RutaAcopio::factory()->create(['codigo' => 'OTRA']);
        $c = $this->rutasComponent()->call('show', $ruta->id)->assertSee('No hay productores activos sin ruta disponibles');
        $p = Productor::factory()->count(3)->create();
        foreach ($p as $producer) {
            $c->set('productorId', (string) $producer->id)->call('addProducer')->assertHasNoErrors();
        }
        $c->set('productorId', (string) $p[0]->id)->call('addProducer')->assertHasErrors('form.productor_id')->assertSee('El productor ya pertenece a esta ruta.');
        $c->call('show', $other->id)->set('productorId', (string) $p[0]->id)->call('addProducer')->assertHasErrors('form.productor_id')->assertSee('Retíralo de esa ruta');
        $c->call('show', $ruta->id)->call('moveProducer', $p[2]->id, -1)->assertHasNoErrors();
        $this->assertSame([$p[0]->id, $p[2]->id, $p[1]->id], $ruta->fresh()->productores->modelKeys());
        $c->call('removeProducer', $p[0]->id)->assertHasNoErrors();
        $this->assertSame([1, 2], $ruta->fresh()->productores->pluck('pivot.orden')->all());
        $collector = $this->user('recolector');
        $c->set('recolectorId', (string) $collector->id)->call('assignCollector')->call('changeState', $ruta->id, false);
        $p[1]->update(['estado' => false]);
        $p[2]->delete();
        $c->call('show', $ruta->id)->assertSee('Advertencia: productor inactivo o eliminado')->assertViewHas('detalle', fn ($row) => $row['productores_count'] === 2);
        $this->assertSame($collector->id, $ruta->fresh()->recolector_id);
        $this->assertDatabaseCount('ruta_productor', 2);
        $c->call('show', $other->id);
        foreach ([$p[1]->id, $p[2]->id, 9999] as $id) {
            $c->set('productorId', (string) $id)->call('addProducer')->assertHasErrors('form.productor_id');
        }
        $c->set('productorId', (string) $p[0]->id)->call('addProducer')->assertHasNoErrors();
    }

    #[TestWith(['producer'])]
    #[TestWith(['order'])]
    public function test_database_constraints_reject_duplicate_assignments(string $conflict): void
    {
        $ruta = RutaAcopio::factory()->create();
        $other = RutaAcopio::factory()->create();
        $p = Productor::factory()->count(2)->create();
        DB::table('ruta_productor')->insert(['ruta_id' => $ruta->id, 'productor_id' => $p[0]->id, 'orden' => 1]);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('ruta_productor')->insert(['ruta_id' => $conflict === 'producer' ? $other->id : $ruta->id, 'productor_id' => $conflict === 'producer' ? $p[0]->id : $p[1]->id, 'orden' => 1]);
    }

    public function test_failed_database_save_leaves_form_retryable(): void
    {
        $c = $this->rutasComponent()->call('create')->set('form', ['codigo' => 'R1', 'nombre' => 'Norte', 'descripcion' => null, 'estado' => true]);
        $real = app(RutaRepository::class);
        $mock = \Mockery::mock(RutaRepository::class);
        $mock->shouldReceive('paginate')->andReturnUsing(fn ($search, $state) => $real->paginate($search, $state));
        $mock->shouldReceive('save')->once()->andThrow(new QueryException('sqlite', 'insert', [], new \Exception('Unavailable')));
        app()->instance(RutaRepository::class, $mock);
        $c->call('save')->assertHasErrors('form.conflicto')->assertSet('modal', true)->assertSee('No se pudo guardar. Intenta nuevamente.');
        app()->instance(RutaRepository::class, $real);
        $c->call('save')->assertHasNoErrors()->assertSet('modal', false);
        $this->assertDatabaseCount('rutas_acopio', 1);
    }

    public function test_listing_query_count_does_not_grow_per_route(): void
    {
        $this->actingAs($this->user());
        $collector = $this->user('recolector');
        RutaAcopio::factory()->create(['recolector_id' => $collector->id]);
        DB::enableQueryLog();
        app(RutaRepository::class)->paginate('', null);
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();
        RutaAcopio::factory()->count(14)->create(['recolector_id' => $collector->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RutaRepository::class)->paginate('', null);
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_inactive_admin_is_denied_and_unknown_routes_are_not_found(): void
    {
        $admin = $this->user();
        $c = Livewire::actingAs($admin)->test('pages::rutas.index');
        User::query()->whereKey($admin->id)->update(['active' => false]);
        Livewire::actingAs($admin)->test('pages::rutas.index')->assertForbidden();
        User::query()->whereKey($admin->id)->update(['active' => true]);
        $this->expectException(ModelNotFoundException::class);
        $c->call('show', 9999);
    }

    public function test_assignment_inputs_and_route_scope_are_checked(): void
    {
        $ruta = RutaAcopio::factory()->create();
        $other = RutaAcopio::factory()->create();
        $producer = Productor::factory()->create();
        $c = $this->rutasComponent()->call('show', $ruta->id);
        foreach (['', 'abc', '-1'] as $value) {
            $c->set('productorId', $value)->call('addProducer')->assertHasErrors('form.productor_id');
        }
        $c->set('recolectorId', 'abc')->call('assignCollector')->assertHasErrors('form.recolector_id');
        $c->set('recolectorId', '')->call('assignCollector')->assertHasErrors('form.recolector_id');
        app(GestionarRutas::class)->attach($other->id, $producer->id);
        $c->call('removeProducer', $producer->id)->assertNotFound();
        $this->assertDatabaseHas('ruta_productor', ['ruta_id' => $other->id, 'productor_id' => $producer->id]);
        Livewire::test('pages::rutas.index')->call('show', $other->id)->call('moveProducer', $producer->id, 0)->assertStatus(422);
    }

    public function test_order_boundaries_and_downward_move(): void
    {
        $ruta = RutaAcopio::factory()->create();
        $producers = Productor::factory()->count(2)->create();
        $c = $this->rutasComponent()->call('show', $ruta->id);
        foreach ($producers as $producer) {
            $c->set('productorId', (string) $producer->id)->call('addProducer');
        }
        $c->call('moveProducer', $producers[0]->id, -1)->call('moveProducer', $producers[1]->id, 1)->assertHasNoErrors();
        $this->assertSame($producers->modelKeys(), $ruta->fresh()->productores->modelKeys());
        $c->call('moveProducer', $producers[0]->id, 1)->assertHasNoErrors();
        $this->assertSame([$producers[1]->id, $producers[0]->id], $ruta->fresh()->productores->modelKeys());
    }

    public function test_form_uses_direct_submit_without_unresolved_dom_references(): void
    {
        $c = $this->rutasComponent()->call('create');
        $document = HTMLDocument::createFromString($c->html(), LIBXML_NOERROR);
        $xpath = new XPath($document);
        $forms = $xpath->query('//*[local-name()="form" and @*[name()="wire:submit" and .="save"]]');
        $this->assertCount(1, $forms);
        $buttons = $xpath->query('.//*[local-name()="button" and @type="submit"]', $forms->item(0));
        $this->assertCount(1, $buttons);
        $this->assertFalse($buttons->item(0)->hasAttribute('disabled'));
        $this->assertStringNotContainsString('$refs', $forms->item(0)->getAttribute('wire:submit'));
        $c->call('save')->assertHasErrors(['form.codigo', 'form.nombre'])->assertSet('modal', true);
    }
}
