<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Comunicados\ConsultarComunicados;
use App\Application\Comunicados\GestionarComunicados;
use App\Http\Controllers\Controller;
use App\Http\Requests\ComunicadoRequest;
use App\Http\Resources\ComunicadoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ComunicadoController extends Controller
{
    public function index(Request $r, ConsultarComunicados $q): AnonymousResourceCollection
    {
        return ComunicadoResource::collection($q->listing($r->user()->id));
    }

    public function show(Request $r, string $uuid, ConsultarComunicados $q): ComunicadoResource
    {
        return new ComunicadoResource($q->find($r->user()->id, $uuid));
    }

    public function read(Request $r, string $uuid, GestionarComunicados $s): JsonResponse
    {
        $s->read($r->user()->id, $uuid);

        return response()->json(['message' => 'Lectura registrada.']);
    }

    public function store(ComunicadoRequest $r, GestionarComunicados $s): JsonResponse
    {
        $record = $s->save($r->user()->id, $r->payload());

        return response()->json(['data' => new ComunicadoResource($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function update(ComunicadoRequest $r, string $uuid, GestionarComunicados $s): ComunicadoResource
    {
        return new ComunicadoResource($s->save($r->user()->id, $r->payload(), $uuid));
    }

    public function action(ComunicadoRequest $r, string $uuid, string $action, GestionarComunicados $s): ComunicadoResource
    {
        $record = match ($action) {
            'publicar' => $s->publish($r->user()->id, $uuid),'anular' => $s->annul($r->user()->id, $uuid, (string) $r->input('motivo', '')),default => abort(404)
        };

        return new ComunicadoResource($record);
    }

    public function link(ComunicadoRequest $r, GestionarComunicados $s): JsonResponse
    {
        return response()->json(['data' => $s->link($r->user()->id, $r->payload())]);
    }
}
