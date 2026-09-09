<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Acopios\ConsultarAcopios;
use App\Application\Acopios\GestionarAcopios;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcopioJornadaRequest;
use App\Http\Requests\AcopioSyncRequest;
use App\Http\Resources\JornadaAcopioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcopioController extends Controller
{
    public function routes(Request $request, ConsultarAcopios $query): JsonResponse
    {
        return response()->json(['message' => 'Rutas consultadas.', 'data' => $query->routesForCollector($request->user()->id)]);
    }

    public function showJourney(Request $request, string $uuid, ConsultarAcopios $query): JornadaAcopioResource
    {
        return (new JornadaAcopioResource($query->journeyForCollector($request->user()->id, $uuid)))->additional(['message' => 'Jornada consultada.']);
    }

    public function storeJourney(AcopioJornadaRequest $request, GestionarAcopios $useCase): JsonResponse
    {
        return (new JornadaAcopioResource($useCase->createJourney($request->user()->id, $request->validated(), true)))->additional(['message' => 'Jornada abierta.'])->response()->setStatusCode(201);
    }

    public function sync(AcopioSyncRequest $request, GestionarAcopios $useCase): JsonResponse
    {
        return response()->json(['message' => 'Sincronización procesada.', 'data' => $useCase->sync($request->user()->id, $request->validated()['entregas'])]);
    }
}
