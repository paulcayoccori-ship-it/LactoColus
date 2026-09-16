<?php

namespace Tests\Feature;

use App\Application\Calidad\GestionarCalidad;
use App\Application\Penalizaciones\GestionarPenalizaciones;
use App\Domain\Calidad\ParametrosCalidad;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Penalizaciones\AsistenciaTecnica;
use App\Infrastructure\Penalizaciones\SancionCalidad;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PenalizacionesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'administrador'): User
    {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(['active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function rules(): array
    {
        return ['activo' => true, 'tarifa_primera' => '1.20', 'tarifa_grave' => '0.65', 'alcance_tarifa' => 'dia', 'unidad_falta' => 'analisis', 'ventana_dias' => null, 'motivo' => 'Tarifas sintéticas solo para prueba'];
    }

    private function service(User $admin): GestionarPenalizaciones
    {
        $s = app(GestionarPenalizaciones::class);
        $s->configure($admin->id, $this->rules());

        return $s;
    }

    public function test_first_water_fault_creates_warning_with_copied_tariff_and_requires_approval(): void
    {
        $a = $this->actor();
        $s = $this->service($a);
        $analysis = AnalisisCalidad::factory()->create(['agua_anadida' => '1']);
        $s->recalculate($a->id, $analysis->productor_id, 'Evaluación de muestra');
        $r = SancionCalidad::sole();
        $this->assertSame('amonestacion', $r->tipo);
        $this->assertSame('pendiente', $r->estado);
        $this->assertSame('1.20', $r->tarifa_penalizada);
        $s->decide($a->id, $r->uuid, ['decision' => 'aprobada', 'motivo' => 'Primera falta revisada']);
        $this->assertSame('aprobada', $r->fresh()->estado);
        $this->assertDatabaseHas('productores', ['id' => $analysis->productor_id, 'estado' => true]);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'decision', 'motivo' => 'Primera falta revisada']);
    }

    public function test_second_fault_requires_separate_loss_and_expulsion_decisions_without_expelling(): void
    {
        $a = $this->actor();
        $s = $this->service($a);
        $first = AnalisisCalidad::factory()->create(['agua_anadida' => '1', 'muestra_at' => now()->subDay()]);
        $second = AnalisisCalidad::factory()->create(['productor_id' => $first->productor_id, 'agua_anadida' => '2']);
        $s->recalculate($a->id, $first->productor_id, 'Evaluar reincidencia');
        $r = SancionCalidad::where('analisis_id', $second->id)->sole();
        $this->assertSame('reincidencia', $r->tipo);
        $this->assertTrue($r->propuesta_perdida);
        $this->assertTrue($r->propuesta_expulsion);
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/penalizaciones/'.$r->uuid.'/decidir', ['decision' => 'aprobada', 'motivo' => 'Revisión'])->assertUnprocessable()->assertJsonValidationErrors(['decision_perdida', 'decision_expulsion']);
        $s->decide($a->id, $r->uuid, ['decision' => 'aprobada', 'decision_perdida' => 'aprobada', 'decision_expulsion' => 'rechazada', 'motivo' => 'Decisión administrativa']);
        $this->assertSame('aprobada', $r->fresh()->decision_perdida);
        $this->assertSame('rechazada', $r->fresh()->decision_expulsion);
        $this->assertDatabaseHas('productores', ['id' => $first->productor_id, 'estado' => true]);
    }

    #[TestWith(['5.0000'])] #[TestWith(['5.0001'])]
    public function test_grave_fault_uses_explicit_tariff_without_automatic_expulsion(string $water): void
    {
        $a = $this->actor();
        $s = $this->service($a);
        $analysis = AnalisisCalidad::factory()->create(['agua_anadida' => $water]);
        $s->recalculate($a->id, $analysis->productor_id, 'Revisión grave');
        $r = SancionCalidad::sole();
        $this->assertSame('grave', $r->tipo);
        $this->assertSame('0.65', $r->tarifa_penalizada);
        $this->assertTrue($r->propuesta_expulsion);
        $this->assertFalse($r->propuesta_perdida);
        $s->decide($a->id, $r->uuid, ['decision' => 'aprobada', 'decision_expulsion' => 'aprobada', 'motivo' => 'Propuesta evaluada']);
        $this->assertDatabaseHas('productores', ['id' => $analysis->productor_id, 'estado' => true]);
    }

    public function test_capture_without_tariff_creates_unapprovable_pending_configuration(): void
    {
        $a = $this->actor();
        $input = AnalisisCalidad::factory()->make(['agua_anadida' => '5'])->only(array_merge(array_keys(ParametrosCalidad::CAMPOS), ['uuid_externo', 'productor_id', 'muestra_at', 'fuente']));
        app(GestionarCalidad::class)->register($a->id, $input);
        $r = SancionCalidad::sole();
        $this->assertSame('pendiente_configuracion', $r->estado);
        $this->assertNull($r->tarifa_penalizada);
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/penalizaciones/'.$r->uuid.'/decidir', ['decision' => 'aprobada', 'decision_expulsion' => 'rechazada', 'motivo' => 'No configurada'])->assertUnprocessable()->assertJsonValidationErrors('estado');
    }

    public function test_analysis_annulment_requires_controlled_recalculation_and_preserves_history(): void
    {
        $a = $this->actor();
        $s = $this->service($a);
        $first = AnalisisCalidad::factory()->create(['agua_anadida' => '1', 'muestra_at' => now()->subDay()]);
        $second = AnalisisCalidad::factory()->create(['agua_anadida' => '1', 'productor_id' => $first->productor_id]);
        $s->recalculate($a->id, $first->productor_id, 'Primera evaluación');
        app(GestionarCalidad::class)->annul($a->id, $first->id, 'Primera muestra inválida');
        $r = SancionCalidad::where('analisis_id', $second->id)->sole();
        $this->assertTrue($r->requiere_revision);
        $s->recalculate($a->id, $first->productor_id, 'Recálculo por anulación');
        $this->assertSame('amonestacion', $r->fresh()->tipo);
        $this->assertSame('pendiente', $r->fresh()->estado);
        $this->assertFalse($r->fresh()->requiere_revision);
        $this->assertDatabaseCount('sanciones_calidad', 2);
        $this->assertDatabaseHas('sanciones_calidad', ['analisis_id' => $first->id, 'estado' => 'anulada']);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'anulacion_por_fuente', 'motivo' => 'Recálculo por anulación']);
    }

    public function test_repeated_evaluation_is_idempotent_and_tariff_changes_require_new_review(): void
    {
        $a = $this->actor();
        $s = $this->service($a);
        $analysis = AnalisisCalidad::factory()->create(['agua_anadida' => '1']);
        $s->recalculate($a->id, $analysis->productor_id, 'Evaluación');
        $r = SancionCalidad::sole();
        $s->decide($a->id, $r->uuid, ['decision' => 'aprobada', 'motivo' => 'Aprobación']);
        $rules = $this->rules();
        $rules['tarifa_primera'] = '1.10';
        $s->configure($a->id, $rules);
        $this->assertSame('1.20', $r->fresh()->tarifa_penalizada);
        $s->recalculate($a->id, $analysis->productor_id, 'Revisión de tarifa aprobada');
        $s->recalculate($a->id, $analysis->productor_id, 'Revisión repetida');
        $this->assertDatabaseCount('sanciones_calidad', 1);
        $this->assertSame('1.10', $r->fresh()->tarifa_penalizada);
        $this->assertSame('pendiente', $r->fresh()->estado);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'recalculo', 'motivo' => 'Revisión de tarifa aprobada']);
    }

    public function test_recurrence_window_and_daily_unit_are_configurable(): void
    {
        $a = $this->actor();
        $s = $this->service($a);
        $rules = $this->rules();
        $rules['unidad_falta'] = 'dia';
        $rules['ventana_dias'] = 2;
        $s->configure($a->id, $rules);
        $old = AnalisisCalidad::factory()->create(['agua_anadida' => '1', 'muestra_at' => now()->subDays(5)]);
        $current = AnalisisCalidad::factory()->create(['agua_anadida' => '1', 'productor_id' => $old->productor_id, 'muestra_at' => now()->subMinute()]);
        $other = AnalisisCalidad::factory()->create(['agua_anadida' => '1', 'productor_id' => $old->productor_id]);
        $s->recalculate($a->id, $old->productor_id, 'Ventana revisada');
        $this->assertSame('amonestacion', SancionCalidad::where('analisis_id', $current->id)->sole()->tipo);
        $this->assertSame('amonestacion', SancionCalidad::where('analisis_id', $other->id)->sole()->tipo);
    }

    public function test_acidity_generates_assistance_without_a_monetary_sanction(): void
    {
        $this->freezeTime();
        $a = $this->actor();
        $s = $this->service($a);
        $analysis = AnalisisCalidad::factory()->create(['acidez' => '0.30', 'agua_anadida' => '0', 'limites_aplicados' => ['activo' => true, 'vigente_desde' => today()->subDay()->toDateString(), 'criterios' => ['acidez' => ['activo' => true, 'minimo' => '0.10', 'maximo' => '0.20', 'desde' => today()->subDay()->toDateString(), 'hasta' => null]]]]);
        $s->recalculate($a->id, $analysis->productor_id, 'Acidez revisada');
        $this->assertDatabaseCount('sanciones_calidad', 0);
        $assistance = AsistenciaTecnica::sole();
        $this->assertSame('pendiente', $assistance->estado);
        $s->assistance($a->id, $assistance->uuid, ['estado' => 'programada', 'responsable_id' => $a->id, 'fecha_at' => now()->addDay()->toDateTimeString(), 'observaciones' => 'Visita técnica', 'motivo' => 'Coordinar asistencia']);
        $this->assertSame('programada', $assistance->fresh()->estado);
        $this->travel(1)->days();
        $s->assistance($a->id, $assistance->uuid, ['estado' => 'realizada', 'responsable_id' => $a->id, 'fecha_at' => now()->toDateTimeString(), 'observaciones' => 'Orientación realizada', 'motivo' => 'Registrar atención']);
        $this->assertSame('realizada', $assistance->fresh()->estado);
        $this->assertDatabaseHas('auditorias_operativas', ['accion' => 'asistencia_realizada']);
    }

    #[TestWith(['recolector'])] #[TestWith(['calidad'])] #[TestWith(['contador'])] #[TestWith(['supervisor'])] #[TestWith(['productor'])]
    public function test_other_roles_cannot_manage_penalties(string $role): void
    {
        $u = $this->actor($role);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/penalizaciones')->assertForbidden();
        $this->postJson('/api/v1/penalizaciones/reglas', $this->rules())->assertForbidden();
        $this->actingAs($u)->get('/admin/penalizaciones')->assertForbidden();
    }

    public function test_manual_annulment_keeps_record_and_requires_reason(): void
    {
        $a = $this->actor();
        $s = $this->service($a);
        $analysis = AnalisisCalidad::factory()->create(['agua_anadida' => '1']);
        $s->recalculate($a->id, $analysis->productor_id, 'Evaluación');
        $r = SancionCalidad::sole();
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/penalizaciones/'.$r->uuid.'/anular', [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $s->annul($a->id, $r->uuid, 'Sanción improcedente');
        $this->assertDatabaseCount('sanciones_calidad', 1);
        $this->assertSame('anulada', $r->fresh()->estado);
    }

    public function test_panel_recovers_and_configuration_requires_explicit_tariffs(): void
    {
        $a = $this->actor();
        $this->actingAs($a);
        Livewire::test('pages::penalizaciones.index')->call('saveRules')->assertHasErrors('tarifa_primera')->set('ruleForm', $this->rules())->call('saveRules')->assertHasNoErrors()->call('recalculate')->assertHasErrors('producer');
    }
}
