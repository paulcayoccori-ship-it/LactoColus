<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Traslados\ConsultarTraslados;
use App\Application\Traslados\GestionarTraslados;
use App\Http\Controllers\Controller;
use App\Http\Requests\TrasladoRequest;
use App\Http\Resources\TrasladoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrasladoController extends Controller
{
    public function index(Request $r, ConsultarTraslados $q): AnonymousResourceCollection
    {
        return TrasladoResource::collection($q->listing($r->user()->id, (string) $r->input('estado', '')));
    }

    public function show(Request $r, string $uuid, ConsultarTraslados $q): TrasladoResource
    {
        return new TrasladoResource($q->find($r->user()->id, $uuid));
    }

    public function options(Request $r, ConsultarTraslados $q): JsonResponse
    {
        return response()->json(['data' => $q->options($r->user()->id)]);
    }

    public function store(TrasladoRequest $r, GestionarTraslados $s): JsonResponse
    {
        $record = $s->request($r->user()->id, $r->payload());

        return response()->json(['data' => new TrasladoResource($record)], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function action(TrasladoRequest $r, string $uuid, string $action, GestionarTraslados $s): TrasladoResource
    {
        $actor = $r->user()->id;
        $record = match ($action) {
            'aprobar' => $s->decide($actor, $uuid, true, (string) $r->input('comentario', '')),'rechazar' => $s->decide($actor, $uuid, false, (string) $r->input('comentario', '')),'cancelar' => $s->cancel($actor, $uuid, (string) $r->input('motivo', '')),'aplicar' => $s->apply($actor, $uuid),default => abort(404)
        };

        return new TrasladoResource($record);
    }

    public function configuration(TrasladoRequest $r, GestionarTraslados $s): JsonResponse
    {
        return response()->json(['data' => $s->configuration($r->user()->id, $r->payload())]);
    }
}
