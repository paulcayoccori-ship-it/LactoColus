<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Liquidaciones\CalcularLiquidaciones;
use App\Application\Liquidaciones\ComprobanteLiquidacion;
use App\Application\Liquidaciones\ConsultarLiquidaciones;
use App\Application\Liquidaciones\GestionarLiquidaciones;
use App\Application\Liquidaciones\GestionarPeriodos;
use App\Http\Controllers\Controller;
use App\Http\Requests\LiquidacionRequest;
use App\Http\Resources\LiquidacionResource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LiquidacionController extends Controller
{
    public function __construct(private ConsultarLiquidaciones $queries, private GestionarLiquidaciones $service, private GestionarPeriodos $periods, private CalcularLiquidaciones $calculator) {}

    public function pdf(Request $r, string $uuid): Response
    {
        $l = $this->queries->find($r->user()->id, $uuid);

        return response(app(ComprobanteLiquidacion::class)->pdf($l), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="liquidacion-'.$l->uuid.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function print(Request $r, string $uuid): View
    {
        $l = $this->queries->find($r->user()->id, $uuid);

        return view('liquidaciones.comprobante', ['liquidacion' => $l, 'lines' => app(ComprobanteLiquidacion::class)->lines($l)]);
    }

    public function index(Request $r): AnonymousResourceCollection
    {
        return LiquidacionResource::collection($this->queries->listing($r->user()->id, $r->only(['periodo', 'estado', 'buscar'])));
    }

    public function show(Request $r, string $uuid): LiquidacionResource
    {
        return new LiquidacionResource($this->queries->find($r->user()->id, $uuid));
    }

    public function periods(Request $r): JsonResponse
    {
        return response()->json($this->queries->periods($r->user()->id));
    }

    public function createPeriod(LiquidacionRequest $r): JsonResponse
    {
        return response()->json($this->periods->create($r->user()->id, $r->payload()), 201);
    }

    public function configure(LiquidacionRequest $r): JsonResponse
    {
        return response()->json($this->periods->configure($r->user()->id, $r->payload()));
    }

    public function periodAction(LiquidacionRequest $r, string $uuid, string $action): JsonResponse
    {
        $reason = $r->input('motivo');
        $r->validate(['motivo' => ['required', 'string', 'max:2000']]);

        return response()->json(match ($action) {
            'cerrar' => $this->periods->close($r->user()->id, $uuid, $reason),'calcular' => $this->calculator->calculate($r->user()->id, $uuid, $reason),'aprobar' => $this->service->approve($r->user()->id, $uuid, $reason),'anular' => $this->periods->annul($r->user()->id, $uuid, $reason)
        });
    }

    public function adjustment(LiquidacionRequest $r, string $uuid): JsonResponse
    {
        return response()->json($this->service->adjustment($r->user()->id, $uuid, $r->payload()), 201);
    }

    public function decideAdjustment(LiquidacionRequest $r, string $uuid): JsonResponse
    {
        return response()->json($this->service->decideAdjustment($r->user()->id, $uuid, $r->payload()));
    }

    public function pay(LiquidacionRequest $r, string $uuid): JsonResponse
    {
        return response()->json($this->service->pay($r->user()->id, $uuid, $r->payload()));
    }
}
