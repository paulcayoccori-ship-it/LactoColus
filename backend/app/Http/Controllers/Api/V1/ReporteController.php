<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Reportes\ConsultarReportes;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteController extends Controller
{
    public function __construct(private ConsultarReportes $reports) {}

    public function options(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->reports->options($r->user()->id)]);
    }

    public function show(Request $r, string $type): JsonResponse
    {
        return response()->json($this->reports->query($r->user()->id, $type, $r->only(['desde', 'hasta', 'ruta_id', 'productor_id', 'recolector_id']))->paginate(50));
    }

    public function csv(Request $r, string $type): StreamedResponse
    {
        $query = $this->reports->query($r->user()->id, $type, $r->only(['desde', 'hasta', 'ruta_id', 'productor_id', 'recolector_id']));

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $headers = false;
            foreach ($query->cursor() as $row) {
                $row = (array) $row;
                if (! $headers) {
                    fputcsv($out, array_keys($row), ',', '"', '');
                    $headers = true;
                } fputcsv($out, array_map(fn ($v) => $this->reports->csvCell($v), array_values($row)), ',', '"', '');
            } if (! $headers) {
                fputcsv($out, ['Sin registros para los filtros indicados'], ',', '"', '');
            } fclose($out);
        }, $type.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
