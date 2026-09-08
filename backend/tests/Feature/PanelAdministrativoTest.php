<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Usuarios\GuardarUsuario;
use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PanelAdministrativoTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function user(string $role = 'administrador', bool $active = true): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['active' => $active]);
        $user->assignRole($role);

        return $user;
    }

    private function form(): array
    {
        return ['name' => 'Ana Quispe', 'email' => 'ana@example.test', 'active' => true, 'roles' => ['supervisor']];
    }

    public function test_guests_cannot_open_administration_and_no_public_registration_or_user_api_exists(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
        $this->get('/admin/usuarios')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->getJson('/api/v1/usuarios')->assertNotFound();
        $this->postJson('/api/v1/usuarios')->assertNotFound();
    }

    #[TestWith(['supervisor'])]
    #[TestWith(['recolector'])]
    #[TestWith(['contador'])]
    public function test_other_roles_are_denied_without_redirect_loops(string $role): void
    {
        $user = $this->user($role);
        foreach (['ver-dashboard', 'administrar-usuarios', 'administrar-productores'] as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability));
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/admin/dashboard');
        $this->get('/admin/dashboard')->assertForbidden()->assertSee('Acceso denegado')->assertSee('Cerrar sesión');
        $this->get('/admin/usuarios')->assertForbidden();
        Livewire::actingAs($user)->test('pages::usuarios.index')->assertForbidden();
        Livewire::actingAs($user)->test('pages::dashboard')->assertForbidden();
        $this->getJson('/api/v1/productores')->assertOk();
    }

    public function test_dashboard_counts_real_non_deleted_producers_and_orders_only_five_recent_records(): void
    {
        $admin = $this->user();
        $this->user('contador', false);
        Productor::factory()->create(['codigo' => 'ANTIGUO', 'created_at' => now()->subDays(10)]);
        Productor::factory()->count(4)->create(['created_at' => now()->subDays(2)]);
        Productor::factory()->inactive()->create(['codigo' => 'RECIENTE', 'created_at' => now()->subDay()]);
        $deleted = Productor::factory()->create(['codigo' => 'ELIMINADO']);
        $deleted->delete();

        Livewire::actingAs($admin)->test('pages::dashboard')
            ->assertViewHas('total', 6)->assertViewHas('activos', 5)->assertViewHas('inactivos', 1)->assertViewHas('usuarios', 2)
            ->assertViewHas('recientes', fn (array $rows): bool => count($rows) === 5 && $rows[0]['codigo'] === 'RECIENTE')
            ->assertSee('RECIENTE')->assertDontSee('ANTIGUO')->assertDontSee('ELIMINADO');
        $this->get('/admin/dashboard')->assertSee('LactoColus')->assertSee('Productores')->assertSee('Usuarios')->assertSee($admin->name)->assertSee('administrador');
        $this->get('/admin/usuarios')->assertOk();
    }

    public function test_empty_dashboard_has_zero_counts_and_empty_message(): void
    {
        Livewire::actingAs($this->user())->test('pages::dashboard')->assertViewHas('total', 0)->assertViewHas('activos', 0)->assertViewHas('inactivos', 0)->assertSee('Aún no hay productores registrados.');
    }

    public function test_create_and_edit_hash_passwords_without_exposing_them_and_preserve_blank_password(): void
    {
        $admin = $this->user();
        Role::findOrCreate('supervisor', 'web');
        Role::findOrCreate('contador', 'web');
        $component = Livewire::actingAs($admin)->test('pages::usuarios.index')->call('create')
            ->set('form', $this->form() + ['auth_version' => 999, 'id' => 999])
            ->call('save', 'NuevaClave123!', 'NuevaClave123!')->assertHasNoErrors()->assertSet('modal', false)
            ->assertDontSee('NuevaClave123!');
        $user = User::query()->where('email', 'ana@example.test')->sole();
        $this->assertTrue(Hash::check('NuevaClave123!', $user->password));
        $this->assertSame(0, $user->auth_version);
        $this->assertTrue($user->hasRole('supervisor', 'web'));
        $hash = $user->password;

        $component->call('open', $user->id)->assertDontSee($hash)->assertDontSee('NuevaClave123!')
            ->set('form.name', '<script>alert(1)</script>')->set('form.roles', ['supervisor', 'contador'])
            ->call('save')->assertHasNoErrors()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->hasAllRoles(['supervisor', 'contador']));

        $component->call('open', $user->id)->call('save', 'OtraClave123!', 'OtraClave123!')->assertHasNoErrors();
        $this->assertTrue(Hash::check('OtraClave123!', $user->fresh()->password));
        $this->assertArrayNotHasKey('password', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('password', $component->get('form'));
    }

    public function test_user_form_resolves_password_references_before_submitting_and_closes_after_creating_admin(): void
    {
        $component = Livewire::actingAs($this->user())->test('pages::usuarios.index')->call('create');
        $document = HTMLDocument::createFromString($component->html(), LIBXML_NOERROR);
        $xpath = new XPath($document);
        $form = $xpath->query('//*[local-name()="form" and @*[name()="wire:submit"]]')->item(0);
        $this->assertNotNull($form);
        preg_match_all('/\\$refs\\.(\\w+)\\.value/', $form->getAttribute('wire:submit'), $references);
        $this->assertCount(2, $references[1]);
        $arguments = [];
        foreach ($references[1] as $reference) {
            $inputs = $xpath->query('.//*[local-name()="input" and @*[name()="wire:ref" and .="'.$reference.'"]]', $form);
            $this->assertCount(1, $inputs, 'La expresión Livewire debe resolver la referencia '.$reference.' en un input del formulario.');
            $this->assertSame('password', $inputs->item(0)->getAttribute('type'));
            $arguments[] = 'ClaveRegresion123!';
        }

        $component->set('form', array_replace($this->form(), ['roles' => ['administrador']]))
            ->call('save', ...$arguments)->assertHasNoErrors()->assertSet('modal', false)
            ->assertDispatched('limpiar-contrasena')->assertDontSee('ClaveRegresion123!');

        $created = User::query()->where('email', 'ana@example.test')->sole();
        $this->assertTrue($created->hasRole('administrador', 'web'));
        $this->assertTrue(Hash::check('ClaveRegresion123!', $created->password));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_required_fields_password_confirmation_and_unique_email(): void
    {
        $admin = $this->user();
        $component = Livewire::actingAs($admin)->test('pages::usuarios.index')->call('create')->call('save')
            ->assertHasErrors(['form.name', 'form.email', 'form.password', 'form.roles']);
        Role::findOrCreate('supervisor', 'web');
        $component->set('form', $this->form())->set('form.email', $admin->email)
            ->call('save', 'NuevaClave123!', 'NoCoincide')->assertHasErrors(['form.email', 'form.password']);
        $this->assertDatabaseCount('users', 1);
    }

    #[TestWith(['name', '', 'form.name'])]
    #[TestWith(['email', 'invalid', 'form.email'])]
    #[TestWith(['roles', ['inventado'], 'form.roles.0'])]
    #[TestWith(['roles', ['supervisor', 'supervisor'], 'form.roles.0'])]
    #[TestWith(['roles', [], 'form.roles'])]
    #[TestWith(['active', 'invalid', 'form.active'])]
    public function test_rejects_invalid_form_values(string $field, mixed $value, string $error): void
    {
        $admin = $this->user();
        Role::findOrCreate('supervisor', 'web');
        Livewire::actingAs($admin)->test('pages::usuarios.index')->call('create')->set('form', array_replace($this->form(), [$field => $value]))
            ->call('save', 'NuevaClave123!', 'NuevaClave123!')->assertHasErrors($error)->assertDontSee('NuevaClave123!');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_length_and_wrong_guard_role_are_rejected(): void
    {
        $admin = $this->user();
        Role::findOrCreate('externo', 'api');
        $component = Livewire::actingAs($admin)->test('pages::usuarios.index')->call('create')->set('form', array_replace($this->form(), ['roles' => ['externo']]))
            ->call('save', 'corta', 'corta')->assertHasErrors(['form.password', 'form.roles.0'])
            ->assertSee('La contraseña debe tener al menos 8 caracteres.');
        $component->call('save', str_repeat('x', 73), str_repeat('x', 73))->assertHasErrors('form.password');
        $component->call('save', str_repeat('é', 40), str_repeat('é', 40))->assertHasErrors('form.password');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_search_filters_and_pagination_are_combined_and_reset_the_page(): void
    {
        $admin = $this->user();
        Role::findOrCreate('supervisor', 'web');
        User::factory()->count(16)->create(['name' => 'Objetivo', 'active' => false])->each(fn (User $user) => $user->assignRole('supervisor'));
        $this->user('contador', false);
        $component = Livewire::actingAs($admin)->test('pages::usuarios.index')->set('search', 'Objetivo')->set('role', 'supervisor')->set('active', '0')
            ->assertViewHas('usuarios', fn ($rows): bool => $rows->total() === 16 && $rows->count() === 15)->assertSee('Mostrando 1 a 15 de 16 resultados')->assertSee('Siguiente');
        $component->call('setPage', 2)->assertViewHas('usuarios', fn ($rows): bool => $rows->count() === 1);
        $component->set('active', '1')->assertSet('paginators.page', 1)->assertSee('No se encontraron usuarios.');
        $component->set('search', $admin->email)->set('role', 'administrador')->assertViewHas('usuarios', fn ($rows): bool => $rows->total() === 1);
    }

    public function test_manipulated_actions_cannot_deactivate_self_or_remove_last_active_admin_role(): void
    {
        $admin = $this->user();
        $this->user('administrador', false);
        Role::findOrCreate('contador', 'web');
        $component = Livewire::actingAs($admin)->test('pages::usuarios.index')
            ->call('changeState', $admin->id, false)->assertHasErrors('form.active')
            ->call('open', $admin->id)->set('form.active', false)->call('save')->assertHasErrors('form.active');
        $component->set('form.active', true)->set('form.roles', ['contador'])->call('save')->assertHasErrors('form.roles');
        $this->assertTrue($admin->fresh()->active);
        $this->assertTrue($admin->fresh()->hasRole('administrador'));
    }

    public function test_self_demotion_is_allowed_when_another_active_admin_remains(): void
    {
        $admin = $this->user();
        $this->user();
        Role::findOrCreate('contador', 'web');
        Livewire::actingAs($admin)->test('pages::usuarios.index')->call('open', $admin->id)->set('form.roles', ['contador'])->call('save')->assertRedirect('/admin/dashboard');
        $this->assertFalse($admin->fresh()->hasRole('administrador'));
        $this->get('/admin/dashboard')->assertForbidden();
    }

    public function test_actions_recheck_revoked_roles_and_inactive_actors(): void
    {
        $admin = $this->user();
        $target = $this->user('contador');
        $component = Livewire::actingAs($admin)->test('pages::usuarios.index')->call('open', $target->id);
        User::query()->findOrFail($admin->id)->removeRole('administrador');
        $component->call('save')->assertForbidden();
        $this->assertSame($target->name, $target->fresh()->name);
    }

    public function test_deactivation_blocks_web_login_existing_session_livewire_and_all_existing_api_tokens(): void
    {
        $admin = $this->user();
        $target = $this->user();
        $firstToken = $target->createToken('first')->plainTextToken;
        $target->createToken('second');
        $component = Livewire::actingAs($target)->test('pages::usuarios.index');
        $this->actingAs($admin);
        app(GuardarUsuario::class)->changeState($admin->id, $target->id, false);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertModelExists($target);
        $this->assertFalse($target->fresh()->active);
        $this->assertSame(1, $target->fresh()->auth_version);

        $this->actingAs($target);
        $component->call('create')->assertForbidden();
        $this->actingAs($target)->withSession(['auth_version' => 0])->get('/admin/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $target->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->postJson('/api/v1/login', ['email' => $target->email, 'password' => 'password'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)->getJson('/api/v1/productores')->assertUnauthorized();
        $this->withToken($firstToken)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_inactive_user_is_blocked_even_if_a_token_survives_outside_the_panel(): void
    {
        $target = $this->user('contador', false);
        $token = $target->createToken('legacy')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/productores')->assertUnauthorized()->assertJsonPath('data', null);
    }

    public function test_reactivation_preserves_password_and_requires_fresh_web_and_api_login(): void
    {
        $admin = $this->user();
        $target = $this->user('contador');
        $hash = $target->password;
        $oldToken = $target->createToken('old')->plainTextToken;
        $this->actingAs($admin);
        app(GuardarUsuario::class)->changeState($admin->id, $target->id, false);
        app(GuardarUsuario::class)->changeState($admin->id, $target->id, true);
        $this->assertSame($hash, $target->fresh()->password);
        $this->actingAs($target)->withSession(['auth_version' => 0])->get('/admin/dashboard')->assertRedirect('/login');
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/productores')->assertUnauthorized();
        $this->post('/login', ['email' => $target->email, 'password' => 'password'])->assertRedirect('/admin/dashboard')->assertSessionHas('auth_version', 1);
        $this->post('/logout');
        $this->postJson('/api/v1/login', ['email' => $target->email, 'password' => 'password'])->assertOk()->assertJsonStructure(['data' => ['token', 'token_type']]);
    }

    public function test_existing_users_receive_active_default_without_changing_passwords(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->fresh()->active);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
