<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Calidad\ConsultarCalidad;
use App\Application\Calidad\GestionarCalidad;
use App\Http\Controllers\Controller;
use App\Http\Requests\CalidadRequest;
use App\Http\Requests\IndexCalidadJornadaRequest;
use App\Http\Requests\SincronizarCalidadRequest;
use App\Http\Resources\AnalisisCalidadResource;
use App\Http\Resources\JornadaAcopioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalidadController extends Controller
{
    public function producers(Request $request, ConsultarCalidad $query): JsonResponse
    {
        return response()->json($query->producers($request->user()->id, (string) $request->query('buscar', '')));
    }

    public function store(CalidadRequest $request, GestionarCalidad $service): JsonResponse
    {
        $result = $service->register($request->user()->id, $request->analysisInput());

        return response()->json(['estado_sincronizacion' => $result['estado'], 'data' => new AnalisisCalidadResource($result['analisis'])], $result['estado'] === 'creado' ? 201 : 200);
    }

    public function sync(SincronizarCalidadRequest $request, GestionarCalidad $service): JsonResponse
    {
        return response()->json(['data' => $service->sync($request->user()->id, $request->validated('analisis'))]);
    }

    public function show(Request $request, string $uuid, ConsultarCalidad $query): AnalisisCalidadResource
    {
        return new AnalisisCalidadResource($query->find($request->user()->id, $uuid));
    }

    public function jornadas(IndexCalidadJornadaRequest $request, ConsultarCalidad $query): AnonymousResourceCollection
    {
        $filters = $request->only(['fecha', 'estado', 'recolector_id']);

        return JornadaAcopioResource::collection($query->jornadas($request->user()->id, $filters))->additional(['message' => 'Jornadas consultadas.']);
    }
}
