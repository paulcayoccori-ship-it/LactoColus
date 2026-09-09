<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Recepciones\GestionarRecepciones;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecepcionLecturaRequest;
use App\Http\Resources\RecepcionResource;
use App\Infrastructure\Acopios\JornadaAcopio;
use Illuminate\Http\JsonResponse;

class RecepcionController extends Controller
{
    public function store(RecepcionLecturaRequest $request, GestionarRecepciones $useCase): JsonResponse
    {
        $data = $request->validated();
        $data['jornada_id'] = JornadaAcopio::query()->where('uuid_publico', $data['jornada_uuid'])->value('id');
        unset($data['jornada_uuid']);
        $result = $useCase->create($request->user()->id, $data, true);

        return response()->json(['message' => $result['estado_sincronizacion'] === 'repetida' ? 'Lectura repetida; se devuelve la recepción existente.' : 'Recepción registrada.', 'data' => ['estado_sincronizacion' => $result['estado_sincronizacion'], 'recepcion' => new RecepcionResource($result['recepcion'])]], $result['estado_sincronizacion'] === 'repetida' ? 200 : 201);
    }
}
