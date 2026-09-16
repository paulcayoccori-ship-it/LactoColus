<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Operacion\ReglasOperativas;
use App\Application\Produccion\ConsultarProduccion;
use App\Application\Produccion\GestionarProduccion;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProduccionRequest;
use App\Http\Resources\LoteProduccionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProduccionController extends Controller
{
    public function index(Request $request, ConsultarProduccion $query): AnonymousResourceCollection
    {
        return LoteProduccionResource::collection($query->listing($request->user()->id, $request->only(['tipo', 'estado', 'buscar'])));
    }

    public function show(Request $request, string $uuid, ConsultarProduccion $query): LoteProduccionResource
    {
        return new LoteProduccionResource($query->find($request->user()->id, $uuid));
    }

    public function store(ProduccionRequest $request, GestionarProduccion $service, ConsultarProduccion $query): JsonResponse
    {
        $lot = $service->create($request->user()->id, $request->payload());

        return response()->json(['data' => new LoteProduccionResource($query->find($request->user()->id, $lot->uuid))], $lot->wasRecentlyCreated ? 201 : 200);
    }

    public function action(ProduccionRequest $request, string $uuid, string $action, GestionarProduccion $service, ConsultarProduccion $query): LoteProduccionResource
    {
        $actor = $request->user()->id;
        $data = $request->payload();
        match ($action) {
            'iniciar' => $service->start($actor, $uuid),
            'finalizar' => $service->finish($actor, $uuid, $data['moldes'] ?? null),
            'ajustar' => $service->adjust($actor, $uuid, $data),
            'anular' => $service->annul($actor, $uuid, (string) ($data['motivo'] ?? '')),
            'corregir' => $service->correctDraft($actor, $uuid, $data, (string) ($data['motivo'] ?? '')),
            default => abort(404),
        };

        return new LoteProduccionResource($query->find($actor, $uuid));
    }

    public function rule(ProduccionRequest $request, ReglasOperativas $rules): JsonResponse
    {
        $data = $request->payload();

        return response()->json(['data' => $rules->saveProduction($request->user()->id, $data, (string) ($data['motivo'] ?? ''))]);
    }
}
