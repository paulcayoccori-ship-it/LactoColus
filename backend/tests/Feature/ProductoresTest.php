<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Productores\Productor;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductoresTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(): array
    {
        return ['codigo' => 'PRO-001', 'dni' => '01234567', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'celular' => '987654321'];
    }

    private function admin(): User
    {
        Role::findOrCreate('administrador', 'web');
        $user = User::factory()->create();
        $user->assignRole('administrador');

        return $user;
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/productores')->assertUnauthorized()->assertJsonPath('message', 'No autenticado.');
        $this->postJson('/api/v1/productores', $this->payload())->assertUnauthorized();
        $this->postJson('/api/v1/logout')->assertUnauthorized();
    }

    public function test_token_login_and_logout_revoke_only_current_token(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $other = $user->createToken('other');
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/productores')->assertOk();
        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/productores')->assertUnauthorized();
    }

    public function test_api_crud_preserves_identifiers_and_soft_deletes(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->postJson('/api/v1/productores', $this->payload() + ['id' => 900, 'deleted_at' => now()])->assertCreated()->assertJsonPath('data.dni', '01234567')->assertJsonPath('data.estado', true)->json('data.id');
        $this->assertNotSame(900, $id);
        $this->getJson('/api/v1/productores/'.$id)->assertOk()->assertJsonPath('data.nombres', 'Ana');
        $this->putJson('/api/v1/productores/'.$id, $this->payload() + ['estado' => false])->assertOk()->assertJsonPath('data.estado', false);
        $this->patchJson('/api/v1/productores/'.$id, ['nombres' => 'María'])->assertOk()->assertJsonPath('data.nombres', 'María');
        $this->assertDatabaseHas('productores', ['id' => $id, 'nombres' => 'María', 'estado' => false]);
        $this->deleteJson('/api/v1/productores/'.$id)->assertNoContent();
        $this->assertSoftDeleted('productores', ['id' => $id]);
        $this->getJson('/api/v1/productores/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/productores/'.$id, ['nombres' => 'Otra'])->assertNotFound();
        $this->deleteJson('/api/v1/productores/'.$id)->assertNotFound();
        $this->postJson('/api/v1/productores', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['codigo', 'dni']);
    }

    #[TestWith(['dni', '1234567', 'El DNI debe tener exactamente 8 dígitos.'])]
    #[TestWith(['dni', '123456789', 'El DNI debe tener exactamente 8 dígitos.'])]
    #[TestWith(['dni', 'abcdefgh', 'El DNI debe tener exactamente 8 dígitos.'])]
    #[TestWith(['celular', '12345678', 'El celular debe tener exactamente 9 dígitos.'])]
    #[TestWith(['email', 'invalid', 'Ingresa un correo electrónico válido.'])]
    #[TestWith(['estado', 'active', 'El estado debe ser activo o inactivo.'])]
    public function test_invalid_data_returns_422_in_spanish(string $field, mixed $value, string $message): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/productores', array_replace($this->payload(), [$field => $value]))->assertUnprocessable()->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('productores', 0);
    }

    public function test_required_fields_and_unique_email(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/productores', [])->assertUnprocessable()->assertJsonValidationErrors(['codigo', 'dni', 'nombres', 'apellidos']);
        $existing = Productor::factory()->create();
        $this->postJson('/api/v1/productores', $this->payload() + ['email' => $existing->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/api/v1/productores/'.$existing->id, ['email' => $existing->email])->assertOk();
    }

    public function test_search_estado_and_pagination(): void
    {
        Productor::factory()->count(3)->inactive()->create(['comunidad' => 'Comunidad objetivo']);
        Productor::factory()->create(['comunidad' => 'Comunidad objetivo']);
        Productor::factory()->inactive()->create(['comunidad' => 'Otra']);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/productores?search=objetivo&estado=0&per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.estado', false);
        $this->getJson('/api/v1/productores?per_page=101&estado=no&page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'estado', 'page']);
    }

    public function test_web_login_logout_and_guest_redirect(): void
    {
        $user = $this->admin();
        $this->get('/login')->assertOk()->assertSee('Iniciar sesión');
        $this->get('/admin/productores')->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/login')->assertRedirect('/admin/dashboard');
        $this->get('/admin/productores')->assertOk()->assertSee('Productores');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[TestWith(['supervisor'])]
    #[TestWith(['recolector'])]
    #[TestWith(['contador'])]
    public function test_non_admin_cannot_access_web(string $role): void
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user)->get('/admin/productores')->assertForbidden();
        Livewire::actingAs($user)->test('pages::productores.index')->assertForbidden();
    }

    public function test_livewire_crud_validation_search_and_escaping(): void
    {
        $component = Livewire::actingAs($this->admin())->test('pages::productores.index');
        $component->call('create')->set('form.dni', 'bad')->call('save')->assertHasErrors('form.dni');
        $component->set('form', $this->payload() + ['estado' => true])->call('save')->assertSet('modal', false);
        $producer = Productor::query()->sole();
        $component->call('open', $producer->id, true)->assertSet('readOnly', true)->assertSet('form.nombres', 'Ana');
        $component->call('open', $producer->id, false)->set('form.nombres', '<script>alert(1)</script>')->call('save')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $component->set('search', 'not present')->assertSee('No se encontraron productores.');
        $component->set('search', '')->call('delete', $producer->id);
        $this->assertSoftDeleted($producer);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'rate@example.test', 'password' => 'bad'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/login', ['email' => 'rate@example.test', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_livewire_paginates_and_blocks_actions_after_role_revocation(): void
    {
        $admin = $this->admin();
        Productor::factory()->count(16)->create();
        $component = Livewire::actingAs($admin)->test('pages::productores.index');
        $component->call('setPage', 2)->assertSet('paginators.page', 2);
        $component->set('search', 'PRO')->assertSet('paginators.page', 1);
        $admin->removeRole('administrador');
        $component->call('create')->assertForbidden();
        $this->assertDatabaseCount('productores', 16);
    }

    public function test_development_seed_is_repeatable_and_uses_web_roles(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class);
        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('productores', 20);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('roles', ['guard_name' => 'sanctum']);
        $this->post('/login', ['email' => 'admin@lactocolus.test', 'password' => 'password'])->assertRedirect('/admin/dashboard');
    }
}
