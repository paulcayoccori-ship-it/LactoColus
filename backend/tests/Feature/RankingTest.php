<?php

namespace Tests\Feature;

use App\Application\Calidad\GestionarCalidad;
use App\Application\Ranking\GestionarRanking;
use App\Infrastructure\Calidad\AnalisisCalidad;
use App\Infrastructure\Productores\Productor;
use App\Infrastructure\Rutas\RutaAcopio;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RankingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'administrador'): User
    {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(['active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function rules(string $privacy = 'codigo', string $aggregation = 'media'): array
    {
        return ['activo' => true, 'privacidad' => $privacy, 'agregacion' => $aggregation, 'inicio_semana' => 'jueves', 'motivo' => 'Criterios sintéticos exclusivos de prueba', 'bandas' => ['solidos_totales' => [['minimo' => '0', 'maximo' => '10', 'puntos' => '20'], ['minimo' => '10', 'maximo' => '100', 'puntos' => '100']], 'densidad_corregida' => [['minimo' => '0', 'maximo' => '2', 'puntos' => '80']], 'acidez' => [['minimo' => '0', 'maximo' => '100', 'puntos' => '60']]]];
    }

    private function payload(string $type = 'diario'): array
    {
        return ['tipo' => $type, 'fecha' => today()->toDateString(), 'motivo' => 'Cálculo de prueba'];
    }

    private function analysis(array $input = []): AnalisisCalidad
    {
        return AnalisisCalidad::factory()->create($input + ['solidos_totales' => '12', 'densidad_corregida' => '1.0300', 'agua_anadida' => '0', 'estado' => 'conforme']);
    }

    public function test_requires_approved_configuration_and_missing_measurements_are_not_invented(): void
    {
        $a = $this->actor();
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/ranking/calcular', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('reglas');
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules());
        $this->analysis(['solidos_totales' => null]);
        $run = $s->calculate($a->id, $this->payload());
        $this->assertNull($run->resultados()->sole()->puntuacion);
        $this->assertSame('pendiente', $run->resultados()->sole()->estado);
        $this->getJson('/api/v1/ranking')->assertJsonCount(0, 'data.resultados');
    }

    public function test_weighted_points_and_duplicate_calculation_are_stable(): void
    {
        $this->freezeTime();
        $a = $this->actor();
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules());
        $this->analysis();
        $r = $s->calculate($a->id, $this->payload());
        $again = $s->calculate($a->id, $this->payload());
        $this->assertSame($r->uuid, $again->uuid);
        $this->assertSame('82.0000', $r->resultados()->sole()->puntuacion);
        $this->assertDatabaseCount('calculos_ranking', 1);
        $this->assertDatabaseCount('fuentes_ranking', 1);
    }

    #[TestWith(['media', '66.0000'])] #[TestWith(['minimo', '50.0000'])]
    public function test_configured_aggregation_is_applied(string $aggregation, string $expected): void
    {
        $a = $this->actor();
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules('codigo', $aggregation));
        $first = $this->analysis();
        $this->analysis(['productor_id' => $first->productor_id, 'solidos_totales' => '9']);
        $r = $s->calculate($a->id, $this->payload());
        $this->assertSame($expected, $r->resultados()->sole()->puntuacion);
    }

    public function test_water_zeroes_period_even_when_other_route_contains_the_water(): void
    {
        $a = $this->actor();
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules());
        $route = RutaAcopio::factory()->create();
        $first = $this->analysis(['ruta_id' => $route->id]);
        $this->analysis(['productor_id' => $first->productor_id, 'ruta_id' => RutaAcopio::factory()->create()->id, 'agua_anadida' => '0.0001', 'solidos_totales' => null]);
        $r = $s->calculate($a->id, $this->payload() + ['ruta_id' => $route->id]);
        $this->assertSame('0.0000', $r->resultados()->sole()->puntuacion);
        $this->assertTrue($r->resultados()->sole()->detalle['agua_anadida']);
    }

    public function test_annulment_hides_stale_public_snapshot_and_recalculation_keeps_history(): void
    {
        $a = $this->actor();
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules());
        $good = $this->analysis();
        $bad = $this->analysis(['productor_id' => $good->productor_id, 'agua_anadida' => '1']);
        $old = $s->calculate($a->id, $this->payload());
        $this->getJson('/api/v1/ranking')->assertJsonPath('data.resultados.0.puntuacion', '0.0000');
        app(GestionarCalidad::class)->annul($a->id, $bad->id, 'Muestra identificada incorrectamente');
        $this->getJson('/api/v1/ranking')->assertJsonCount(0, 'data.resultados');
        $new = $s->calculate($a->id, $this->payload());
        $this->assertSame('82.0000', $new->resultados()->sole()->puntuacion);
        $this->assertSame('0.0000', $old->resultados()->sole()->puntuacion);
        $this->assertDatabaseCount('calculos_ranking', 2);
        $this->assertDatabaseCount('analisis_calidad', 2);
    }

    public function test_new_rule_keeps_historical_scores_and_ties_follow_producer_code(): void
    {
        $a = $this->actor();
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules());
        $this->analysis(['productor_id' => Productor::factory()->create(['codigo' => 'ZZZ'])->id]);
        $this->analysis(['productor_id' => Productor::factory()->create(['codigo' => 'AAA'])->id]);
        $r = $s->calculate($a->id, $this->payload());
        $this->assertSame('AAA', $r->resultados()->where('posicion', 1)->sole()->productor_snapshot['codigo']);
        $rules = $this->rules();
        $rules['bandas']['acidez'][0]['puntos'] = '100';
        $s->configure($a->id, $rules);
        $new = $s->calculate($a->id, $this->payload());
        $this->assertSame('94.0000', $new->resultados()->first()->puntuacion);
        $this->assertSame('82.0000', $r->resultados()->first()->puntuacion);
        $this->assertSame(1, $r->regla_aplicada['version']);
        $this->assertSame(2, $new->regla_aplicada['version']);
    }

    #[TestWith(['codigo', 'P-PRIVADO'])] #[TestWith(['nombre_abreviado', 'Ana P.'])] #[TestWith(['nombre_completo', 'Ana María Pérez Ruiz'])]
    public function test_public_privacy_never_returns_personal_or_financial_fields(string $privacy, string $label): void
    {
        $a = $this->actor();
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules($privacy));
        $this->analysis(['productor_id' => Productor::factory()->create(['codigo' => 'P-PRIVADO', 'nombres' => 'Ana María', 'apellidos' => 'Pérez Ruiz', 'dni' => '99887766', 'celular' => '999888777'])->id]);
        $s->calculate($a->id, $this->payload());
        $response = $this->getJson('/api/v1/ranking')->assertOk()->assertJsonPath('data.resultados.0.productor', $label);
        $this->assertStringNotContainsString('99887766', $response->getContent());
        $this->assertStringNotContainsString('999888777', $response->getContent());
        $this->assertSame(['posicion', 'productor', 'rutas', 'puntuacion'], array_keys($response->json('data.resultados.0')));
    }

    public function test_weekly_and_monthly_periods_use_configured_calendar(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 10)->setTime(12, 0));
        $a = $this->actor();
        $s = app(GestionarRanking::class);
        $s->configure($a->id, $this->rules());
        $week = $s->calculate($a->id, $this->payload('semanal'));
        $month = $s->calculate($a->id, $this->payload('mensual'));
        $this->assertSame('2026-09-10', $week->desde->toDateString());
        $this->assertSame('2026-09-16', $week->hasta->toDateString());
        $this->assertSame('2026-09-01', $month->desde->toDateString());
        $this->assertSame('2026-09-30', $month->hasta->toDateString());
    }

    public function test_gaps_overlaps_and_out_of_range_points_are_rejected(): void
    {
        Sanctum::actingAs($this->actor());
        $rules = $this->rules();
        $rules['bandas']['solidos_totales'][1]['minimo'] = '11';
        $this->postJson('/api/v1/ranking/reglas', $rules)->assertUnprocessable()->assertJsonValidationErrors('bandas.solidos_totales');
        $rules['bandas']['solidos_totales'][1]['minimo'] = '9';
        $this->postJson('/api/v1/ranking/reglas', $rules)->assertUnprocessable();
        $rules = $this->rules();
        $rules['bandas']['acidez'][0]['puntos'] = '101';
        $this->postJson('/api/v1/ranking/reglas', $rules)->assertUnprocessable();
    }

    #[TestWith(['contador'])] #[TestWith(['calidad'])] #[TestWith(['recolector'])] #[TestWith(['supervisor'])] #[TestWith(['productor'])]
    public function test_non_admins_cannot_configure_or_recalculate(string $role): void
    {
        $u = $this->actor($role);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/ranking/reglas', $this->rules())->assertForbidden();
        $this->postJson('/api/v1/ranking/calcular', $this->payload())->assertForbidden();
        $this->actingAs($u)->get('/admin/ranking')->assertForbidden();
    }

    public function test_admin_panel_and_public_wall_render_and_buttons_recover(): void
    {
        $a = $this->actor();
        $this->actingAs($a);
        $panel = Livewire::test('pages::ranking.index')->call('saveRules')->assertHasErrors();
        $panel->set('form', $this->rules())->call('saveRules')->assertHasNoErrors()->set('calculation', $this->payload())->call('calculate')->assertHasNoErrors()->assertSee('bandas_ponderadas_v1');
        $this->get('/muro-de-honor')->assertOk()->assertSee('Muro de Honor');
    }
}
