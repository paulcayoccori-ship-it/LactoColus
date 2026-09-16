<?php

namespace Tests\Feature;

use App\Application\Reportes\ConsultarReportes;
use App\Infrastructure\Acopios\EntregaAcopio;
use App\Infrastructure\Acopios\JornadaAcopio;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReportesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'administrador'): User
    {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(['active' => true]);
        $u->assignRole($role);

        return $u;
    }

    public function test_all_catalog_queries_are_valid_and_read_only(): void
    {
        $a = $this->actor();
        $q = app(ConsultarReportes::class);
        foreach ($q->options($a->id) as $type => $name) {
            $this->assertNotNull($q->query($a->id, $type)->get(), $type);
        }
    }

    public function test_accountant_only_has_financial_reports(): void
    {
        $c = $this->actor('contador');
        $q = app(ConsultarReportes::class);
        $this->assertSame(['liquidaciones', 'pagos'], array_keys($q->options($c->id)));
        $this->expectException(HttpException::class);
        $q->query($c->id, 'calidad-productor');
    }

    public function test_csv_formula_cells_are_escaped(): void
    {
        $q = app(ConsultarReportes::class);
        $this->assertSame("'=SUM(A1)", $q->csvCell('=SUM(A1)'));
        $this->assertSame('10.125', $q->csvCell('10.125'));
    }

    public function test_api_filters_volume_and_exports_without_private_fields(): void
    {
        $a = $this->actor();
        Sanctum::actingAs($a);
        $j = JornadaAcopio::factory()->create(['estado' => 'cerrada']);
        EntregaAcopio::factory()->create(['jornada_id' => $j->id, 'litros' => '10.125', 'recolectada_at' => '2026-09-03 10:00:00']);
        $void = JornadaAcopio::factory()->create(['estado' => 'anulada']);
        EntregaAcopio::factory()->create(['jornada_id' => $void->id, 'litros' => '100.000', 'recolectada_at' => '2026-09-03 10:00:00']);
        $this->getJson('/api/v1/reportes/volumen-periodo?desde=2026-09-03&hasta=2026-09-03')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.entregas', 1);
        $this->getJson('/api/v1/reportes/volumen-periodo?desde=2026-09-04&hasta=2026-09-04')->assertOk()->assertJsonCount(0, 'data');
        $csv = $this->get('/api/v1/reportes/volumen-productor/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('10.125', $csv);
        $this->assertStringNotContainsString('dni', $csv);
        $this->assertStringNotContainsString('celular', $csv);
        $this->getJson('/api/v1/reportes/ventas?ruta_id=1')->assertUnprocessable();
        $this->actingAs($a);
        Livewire::test('pages::reportes.index')->set('filters.desde', 'no-fecha')->call('apply')->assertHasErrors('desde')->set('filters.desde', '2026-09-03')->call('apply')->assertHasNoErrors();
    }

    public function test_unauthorized_roles_cannot_export_or_manipulate_report_type(): void
    {
        foreach (['recolector', 'supervisor', 'calidad', 'productor'] as $role) {
            $u = $this->actor($role);
            Sanctum::actingAs($u);
            $this->getJson('/api/v1/reportes')->assertForbidden();
            $this->get('/api/v1/reportes/pagos/csv')->assertForbidden();
        }
        $c = $this->actor('contador');
        Sanctum::actingAs($c);
        $this->getJson('/api/v1/reportes/liquidaciones')->assertOk();
        $this->getJson('/api/v1/reportes/ventas')->assertForbidden();
        $this->get('/api/v1/reportes/penalizaciones/csv')->assertForbidden();
    }
}
