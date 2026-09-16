<?php

namespace Tests\Feature;

use App\Application\Calidad\ConsultarCalidad;
use App\Application\Calidad\GestionarCalidad;
use App\Domain\Calidad\CalidadRepository;
use App\Domain\Calidad\ParametrosCalidad;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Calidad\AuditoriaCalidad;
use App\Infrastructure\Calidad\PerfilCalidad;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalidadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'calidad', bool $active = true): User
    {
        $this->travelTo(Carbon::parse('2026-09-09 12:00:00'));
        Role::findOrCreate('administrador', 'web');
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['active' => $active]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(): array
    {
        return ['uuid_externo' => (string) Str::uuid(), 'productor_id' => Productor::factory()->create(['estado' => true])->id, 'muestra_at' => '2026-09-09 08:00:00', 'fuente' => 'manual', 'grasa' => '3.5000', 'proteina' => '3.2000', 'lactosa' => '4.5000', 'densidad_medida' => '1.0300', 'densidad_corregida' => '1.0300', 'temperatura' => '20', 'solidos_no_grasos' => '8.5', 'ph' => '6.7', 'acidez' => '0.15', 'agua_anadida' => '0'];
    }

    /** Rangos deliberadamente sintéticos: no son criterios técnicos de producción. */
    private function profile(): array
    {
        $data = ['nombre' => 'Solo para pruebas', 'activo' => true, 'vigente_desde' => '2026-01-01', 'vigente_hasta' => null, 'criterios' => []];
        foreach (ParametrosCalidad::criteria() as $key => $field) {
            $data['criterios'][$key] = ['minimo' => '0', 'maximo' => $field[2], 'unidad' => $field[1], 'activo' => true, 'desde' => '2026-01-01', 'hasta' => null];
        }
        $data['criterios']['grasa']['maximo'] = '4';

        return $data;
    }

    public function test_valid_analysis_pending_without_profile_and_density_is_not_invented(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user);
        $input = $this->payload();
        unset($input['densidad_corregida']);
        $response = $this->postJson('/api/v1/calidad/analisis', $input)->assertCreated()->assertJsonPath('data.estado', 'pendiente_revision')->assertJsonPath('data.densidad_corregida', null)->assertJsonPath('data.grasa', '3.5000');
        $this->assertDatabaseHas('analisis_calidad', ['uuid_externo' => $input['uuid_externo'], 'responsable_id' => $user->id]);
        $this->getJson('/api/v1/calidad/analisis/'.$response->json('data.uuid_publico'))->assertOk();
    }

    #[TestWith(['grasa', '-1'])]
    #[TestWith(['agua_anadida', '101'])]
    #[TestWith(['ph', '15'])]
    #[TestWith(['densidad_medida', '300'])]
    #[TestWith(['densidad_medida', '0'])]
    #[TestWith(['temperatura', '200'])]
    #[TestWith(['proteina', '1.12345'])]
    #[TestWith(['acidez', 'NaN'])]
    #[TestWith(['ruta_id', 999])]
    #[TestWith(['responsable_id', 999])]
    #[TestWith(['estado', 'conforme'])]
    #[TestWith(['muestra_at', '2030-01-01'])]
    public function test_invalid_measurements_and_server_fields_rejected(string $field, mixed $value): void
    {
        Sanctum::actingAs($this->actor());
        $input = $this->payload();
        $input[$field] = $value;
        $this->postJson('/api/v1/calidad/analisis', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('analisis_calidad', 0);
    }

    #[TestWith(['administrador', true])]
    #[TestWith(['calidad', true])]
    #[TestWith(['supervisor', false])]
    #[TestWith(['recolector', false])]
    #[TestWith(['contador', false])]
    public function test_permission_matrix(string $role, bool $allowed): void
    {
        $actor = $this->actor($role);
        $this->assertSame($allowed, Gate::forUser($actor)->allows('operar-calidad'));
        $this->assertSame($role === 'administrador', Gate::forUser($actor)->allows('administrar-calidad'));
        Sanctum::actingAs($actor);
        $response = $this->getJson('/api/v1/calidad/productores');
        $response->assertStatus($allowed ? 200 : 403);
    }

    public function test_authentication_and_inactive_users_are_rejected(): void
    {
        $this->getJson('/api/v1/calidad/productores')->assertUnauthorized();
        Sanctum::actingAs($this->actor('calidad', false));
        $this->getJson('/api/v1/calidad/productores')->assertUnauthorized();
    }

    public function test_quality_can_only_see_own_records_and_active_producers(): void
    {
        $one = $this->actor();
        $two = $this->actor();
        $input = $this->payload();
        $record = app(GestionarCalidad::class)->register($one->id, $input)['analisis'];
        $inactive = Productor::factory()->create(['estado' => false]);
        Sanctum::actingAs($two);
        $this->getJson('/api/v1/calidad/analisis/'.$record->uuid_publico)->assertNotFound();
        $this->postJson('/api/v1/calidad/analisis', $input)->assertForbidden();
        $this->getJson('/api/v1/calidad/productores')->assertJsonMissing(['id' => $inactive->id, 'codigo' => $inactive->codigo]);
        $input['uuid_externo'] = (string) Str::uuid();
        $input['productor_id'] = $inactive->id;
        $this->postJson('/api/v1/calidad/analisis', $input)->assertUnprocessable()->assertJsonValidationErrors('productor_id');
        $this->actingAs($two)->get('/admin/calidad/perfiles')->assertForbidden();
    }

    public function test_relationships_are_validated_and_route_history_survives_transfer(): void
    {
        $user = $this->actor();
        $input = $this->payload();
        $route = RutaAcopio::factory()->create();
        $journey = JornadaAcopio::factory()->create(['ruta_id' => $route->id]);
        Sanctum::actingAs($user);
        $input['jornada_id'] = $journey->id;
        $this->postJson('/api/v1/calidad/analisis', $input)->assertUnprocessable()->assertJsonValidationErrors('jornada_id');
        DB::table('ruta_productor')->insert(['productor_id' => $input['productor_id'], 'ruta_id' => $route->id, 'orden' => 1]);
        $response = $this->postJson('/api/v1/calidad/analisis', $input)->assertCreated()->assertJsonPath('data.ruta_id', $route->id);
        DB::table('ruta_productor')->where('productor_id', $input['productor_id'])->update(['ruta_id' => RutaAcopio::factory()->create()->id]);
        Productor::find($input['productor_id'])->delete();
        $this->getJson('/api/v1/calidad/analisis/'.$response->json('data.uuid_publico'))->assertOk()->assertJsonPath('data.ruta_id', $route->id);
    }

    public function test_delivery_must_belong_to_producer_and_match_journey(): void
    {
        $user = $this->actor();
        Sanctum::actingAs($user);
        $input = $this->payload();
        $delivery = EntregaAcopio::factory()->create();
        $input['entrega_id'] = $delivery->id;
        $this->postJson('/api/v1/calidad/analisis', $input)->assertUnprocessable()->assertJsonValidationErrors('entrega_id');
        $input['productor_id'] = $delivery->productor_id;
        $input['jornada_id'] = JornadaAcopio::factory()->create()->id;
        $this->postJson('/api/v1/calidad/analisis', $input)->assertUnprocessable()->assertJsonValidationErrors('jornada_id');
        unset($input['jornada_id']);
        $this->postJson('/api/v1/calidad/analisis', $input)->assertCreated()->assertJsonPath('data.jornada_id', $delivery->jornada_id);
    }

    public function test_profiles_evaluate_and_preserve_limits_on_correction(): void
    {
        $admin = $this->actor('administrador');
        $service = app(GestionarCalidad::class);
        $profile = $service->saveProfile($admin->id, $this->profile());
        $input = $this->payload();
        $record = $service->register($admin->id, $input)['analisis'];
        $this->assertSame('conforme', $record->estado);
        $new = $this->profile();
        $new['criterios']['grasa']['maximo'] = '3';
        $service->saveProfile($admin->id, $new);
        $corrected = $service->correct($admin->id, $record->id, $input, 'Relectura');
        $this->assertSame('conforme', $corrected->estado);
        $this->assertSame($profile->id, $corrected->perfil_id);
        $this->assertSame('4.0000', $corrected->limites_aplicados['criterios']['grasa']['maximo']);
        $reviewed = $service->review($admin->id, $record->id, 'Aplicar criterio actualizado');
        $this->assertSame('observado', $reviewed->estado);
        $this->assertArrayHasKey('grasa', $reviewed->advertencias);
        $audit = AuditoriaCalidad::where('accion', 'revision')->sole();
        $this->assertSame('conforme', $audit->anteriores['estado']);
        $this->assertSame('observado', $audit->nuevos['estado']);
    }

    public function test_incomplete_inactive_and_expired_criteria_leave_pending(): void
    {
        $admin = $this->actor('administrador');
        $profile = $this->profile();
        $profile['criterios']['ph']['maximo'] = null;
        $service = app(GestionarCalidad::class);
        $service->saveProfile($admin->id, $profile);
        $record = $service->register($admin->id, $this->payload())['analisis'];
        $this->assertSame('pendiente_revision', $record->estado);
        $profile = $this->profile();
        $profile['criterios']['ph']['hasta'] = '2026-09-08';
        $service->saveProfile($admin->id, $profile);
        $this->assertSame('pendiente_revision', $service->review($admin->id, $record->id, 'Criterios vencidos')->estado);
        $profile = $this->profile();
        $profile['activo'] = false;
        $service->saveProfile($admin->id, $profile);
        $this->assertArrayHasKey('perfil', $service->review($admin->id, $record->id, 'Suspensión')->advertencias);
    }

    public function test_uuid_is_idempotent_and_batch_reports_all_items(): void
    {
        $actor = $this->actor();
        Sanctum::actingAs($actor);
        $input = $this->payload();
        $first = $this->postJson('/api/v1/calidad/analisis', $input)->assertCreated();
        $this->postJson('/api/v1/calidad/analisis', ['uuid_externo' => $input['uuid_externo']])->assertOk()->assertJsonPath('data.uuid_publico', $first->json('data.uuid_publico'));
        $new = $input;
        $new['uuid_externo'] = (string) Str::uuid();
        $bad = $new;
        $bad['uuid_externo'] = (string) Str::uuid();
        $bad['ph'] = '99';
        $this->postJson('/api/v1/calidad/sincronizar', ['analisis' => [$input, $new, $bad, 'no objeto']])->assertOk()->assertJsonCount(1, 'data.creados')->assertJsonCount(1, 'data.repetidos')->assertJsonCount(2, 'data.rechazados');
        $this->assertDatabaseCount('analisis_calidad', 2);
    }

    public function test_annulment_audits_and_dashboard_excludes_annulled_records(): void
    {
        $admin = $this->actor('administrador');
        $service = app(GestionarCalidad::class);
        $record = $service->register($admin->id, $this->payload())['analisis'];
        $this->actingAs($admin);
        Livewire::test('pages::dashboard')->assertViewHas('analisis_hoy', 1)->assertViewHas('analisis_pendientes', 1);
        $service->annul($admin->id, $record->id, 'Muestra invalidada');
        $this->assertDatabaseHas('auditorias_calidad', ['analisis_id' => $record->id, 'usuario_id' => $admin->id, 'accion' => 'anulacion', 'motivo' => 'Muestra invalidada']);
        Livewire::test('pages::dashboard')->assertViewHas('analisis_hoy', 0)->assertViewHas('analisis_pendientes', 0);
        $this->expectException(ValidationException::class);
        $service->correct($admin->id, $record->id, $this->payload(), 'No permitido');
    }

    public function test_panel_recovers_after_error_and_quality_cannot_correct(): void
    {
        $admin = $this->actor('administrador');
        $input = $this->payload();
        $this->actingAs($admin);
        $panel = Livewire::test('pages::calidad.index')->call('create')->call('save')->assertHasErrors('productor_id')->assertSet('modal', true);
        $panel->set('form', $input)->call('save')->assertHasNoErrors()->assertSet('modal', false);
        $record = AnalisisCalidad::sole();
        $panel->call('show', $record->uuid_publico)->call('annul')->assertHasErrors('motivo');
        $quality = $this->actor();
        $this->actingAs($quality);
        Livewire::test('pages::calidad.index')->call('edit')->assertForbidden();
    }

    public function test_profile_screen_versions_and_validates_ranges(): void
    {
        $admin = $this->actor('administrador');
        $this->actingAs($admin);
        $profile = $this->profile();
        $profile['criterios']['grasa']['minimo'] = '9';
        $panel = Livewire::test('pages::calidad.perfiles')->set('form', $profile)->call('save')->assertHasErrors('criterios.grasa.maximo');
        $panel->set('form', $this->profile())->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('perfiles_calidad', 1);
        $panel->call('copy', PerfilCalidad::sole()->id)->set('form.nombre', 'Nueva versión')->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('perfiles_calidad', 2);
    }

    public function test_quality_login_redirects_to_quality_without_dashboard_access(): void
    {
        $user = $this->actor();
        $user->password = 'Test-password-123';
        $user->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'Test-password-123'])->assertRedirect(route('admin.calidad'));
        $this->get('/admin/dashboard')->assertForbidden();
        $this->get('/admin/calidad')->assertOk()->assertSee('Control de calidad');
    }

    public function test_correction_audits_decimal_values_and_observed_dashboard(): void
    {
        $admin = $this->actor('administrador');
        $service = app(GestionarCalidad::class);
        $service->saveProfile($admin->id, $this->profile());
        $input = $this->payload();
        $record = $service->register($admin->id, $input)['analisis'];
        $input['grasa'] = '5.1234';
        $corrected = $service->correct($admin->id, $record->id, $input, 'Segunda lectura');
        $this->assertSame('observado', $corrected->estado);
        $audit = AuditoriaCalidad::sole();
        $this->assertSame('3.5000', $audit->anteriores['grasa']);
        $this->assertSame('5.1234', $audit->nuevos['grasa']);
        $this->assertSame($admin->id, $audit->usuario_id);
        $this->assertNotNull($audit->created_at);
        $this->actingAs($admin);
        Livewire::test('pages::dashboard')->assertViewHas('analisis_observados', 1);
    }

    public function test_filters_and_paginated_quality_listing_do_not_expose_other_owners(): void
    {
        $quality = $this->actor();
        $other = $this->actor();
        AnalisisCalidad::factory()->count(16)->create(['responsable_id' => $quality->id, 'agua_anadida' => '1.0000']);
        AnalisisCalidad::factory()->create(['responsable_id' => $other->id]);
        $query = app(ConsultarCalidad::class);
        $page = $query->listing($quality->id, ['agua' => 'si', 'estado' => 'pendiente_revision']);
        $this->assertSame(16, $page->total());
        $this->assertCount(15, $page->items());
        $this->assertSame(0, $query->listing($quality->id, ['responsable_id' => $other->id])->total());
        $this->assertSame(0, $query->listing($quality->id, ['desde' => '2027-01-01'])->total());
    }

    public function test_removed_quality_role_denies_old_session_and_mutating_actions(): void
    {
        $quality = $this->actor();
        $record = app(GestionarCalidad::class)->register($quality->id, $this->payload())['analisis'];
        $this->actingAs($quality);
        $panel = Livewire::test('pages::calidad.index');
        $quality->removeRole('calidad');
        $panel->call('show', $record->uuid_publico)->assertForbidden();
        $this->assertDatabaseHas('analisis_calidad', ['id' => $record->id, 'estado' => 'pendiente_revision']);
    }

    public function test_profile_units_and_range_order_are_validated_without_creating_versions(): void
    {
        $admin = $this->actor('administrador');
        $this->actingAs($admin);
        $input = $this->profile();
        $input['criterios']['densidad_corregida']['unidad'] = 'kg/L arbitrario';
        Livewire::test('pages::calidad.perfiles')->set('form', $input)->call('save')->assertHasErrors('criterios.densidad_corregida.unidad');
        $this->assertDatabaseCount('perfiles_calidad', 0);
    }

    public function test_correcting_sample_date_outside_snapshot_validity_leaves_pending(): void
    {
        $admin = $this->actor('administrador');
        $service = app(GestionarCalidad::class);
        $profile = $this->profile();
        $profile['vigente_desde'] = '2026-09-09';
        $service->saveProfile($admin->id, $profile);
        $input = $this->payload();
        $record = $service->register($admin->id, $input)['analisis'];
        $input['muestra_at'] = '2026-09-08 08:00:00';
        $corrected = $service->correct($admin->id, $record->id, $input, 'Fecha transcrita incorrectamente');
        $this->assertSame('pendiente_revision', $corrected->estado);
        $this->assertArrayHasKey('perfil', $corrected->advertencias);
    }

    public function test_review_uses_configured_timezone_at_midnight_boundary(): void
    {
        $originalTimezone = date_default_timezone_get();
        config(['app.timezone' => 'America/Lima']);
        date_default_timezone_set('America/Lima');
        try {
            $admin = $this->actor('administrador');
            $service = app(GestionarCalidad::class);
            $profile = $this->profile();
            $profile['vigente_hasta'] = '2026-09-08';
            foreach ($profile['criterios'] as &$criterion) {
                $criterion['hasta'] = '2026-09-08';
            }
            unset($criterion);
            $service->saveProfile($admin->id, $profile);
            $input = $this->payload();
            $input['muestra_at'] = '2026-09-08T23:30:00-05:00';
            $record = $service->register($admin->id, $input)['analisis'];
            $this->assertSame('conforme', $record->estado);
            $this->assertSame('conforme', $service->review($admin->id, $record->id, 'Revisión en zona local')->estado);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_journeys_endpoint_returns_todays_journeys_with_deliveries_and_analysis_flag(): void
    {
        $collector = User::factory()->create();
        $route = RutaAcopio::factory()->create();
        $journey = JornadaAcopio::factory()->create(['ruta_id' => $route->id, 'recolector_id' => $collector->id, 'fecha_operativa' => '2026-09-09', 'estado' => 'abierta']);
        $analyzed = EntregaAcopio::factory()->create(['jornada_id' => $journey->id, 'ruta_id' => $route->id, 'recolector_id' => $collector->id, 'litros' => '10.500']);
        $pending = EntregaAcopio::factory()->create(['jornada_id' => $journey->id, 'ruta_id' => $route->id, 'recolector_id' => $collector->id, 'litros' => '5.250']);
        AnalisisCalidad::factory()->create(['entrega_id' => $analyzed->id, 'productor_id' => $analyzed->productor_id]);
        JornadaAcopio::factory()->create(['fecha_operativa' => '2026-09-08']);

        Sanctum::actingAs($this->actor());
        $response = $this->getJson('/api/v1/calidad/jornadas')->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $journey->id);
        $response->assertJsonPath('data.0.recolector', $collector->name);
        $response->assertJsonPath('data.0.recolector_id', $collector->id);
        $response->assertJsonPath('data.0.cantidad_entregas', 2);
        $response->assertJsonPath('data.0.litros', '15.75');
        $response->assertJsonPath('data.0.fecha_operativa', '2026-09-09');
        $response->assertJsonCount(2, 'data.0.entregas');
        $ids = collect($response->json('data.0.entregas'))->keyBy('id');
        $this->assertTrue($ids[$analyzed->id]['tiene_analisis']);
        $this->assertFalse($ids[$pending->id]['tiene_analisis']);
        $this->assertSame($analyzed->productor->codigo, $ids[$analyzed->id]['productor_codigo']);
        $this->assertSame($analyzed->productor->nombres, $ids[$analyzed->id]['productor_nombres']);
        $this->assertSame($analyzed->productor->apellidos, $ids[$analyzed->id]['productor_apellidos']);
    }

    public function test_journeys_endpoint_filters_by_fecha_estado_and_recolector(): void
    {
        $collectorOne = User::factory()->create();
        $collectorTwo = User::factory()->create();
        JornadaAcopio::factory()->create(['recolector_id' => $collectorOne->id, 'fecha_operativa' => '2026-09-09', 'estado' => 'abierta']);
        JornadaAcopio::factory()->create(['recolector_id' => $collectorOne->id, 'fecha_operativa' => '2026-09-09', 'estado' => 'cerrada']);
        JornadaAcopio::factory()->create(['recolector_id' => $collectorTwo->id, 'fecha_operativa' => '2026-09-09', 'estado' => 'abierta']);
        JornadaAcopio::factory()->create(['recolector_id' => $collectorOne->id, 'fecha_operativa' => '2026-09-07', 'estado' => 'abierta']);

        Sanctum::actingAs($this->actor());
        $this->getJson('/api/v1/calidad/jornadas?estado=cerrada')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'cerrada');
        $this->getJson('/api/v1/calidad/jornadas?recolector_id='.$collectorOne->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/calidad/jornadas?fecha=2026-09-07')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fecha_operativa', '2026-09-07');
        $this->getJson('/api/v1/calidad/jornadas')->assertOk()->assertJsonCount(3, 'data');
    }

    #[TestWith(['administrador', true])]
    #[TestWith(['calidad', true])]
    #[TestWith(['recolector', false])]
    public function test_journeys_endpoint_permission_matrix(string $role, bool $allowed): void
    {
        Sanctum::actingAs($this->actor($role));
        $this->getJson('/api/v1/calidad/jornadas')->assertStatus($allowed ? 200 : 403);
    }

    public function test_journeys_endpoint_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/calidad/jornadas')->assertUnauthorized();
    }

    public function test_journeys_repository_query_count_does_not_grow_with_deliveries(): void
    {
        $repository = app(CalidadRepository::class);
        $journey = JornadaAcopio::factory()->create(['fecha_operativa' => '2026-09-09']);
        EntregaAcopio::factory()->create(['jornada_id' => $journey->id]);
        DB::enableQueryLog();
        $repository->jornadas(['fecha' => '2026-09-09']);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        EntregaAcopio::factory()->count(19)->create(['jornada_id' => $journey->id]);
        DB::flushQueryLog();
        $result = $repository->jornadas(['fecha' => '2026-09-09']);
        $this->assertCount(20, $result->first()['entregas']);
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
