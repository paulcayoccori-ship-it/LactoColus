<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Penalizaciones\ConsultarPenalizaciones;
use App\Application\Penalizaciones\GestionarPenalizaciones;
use App\Http\Controllers\Controller;
use App\Http\Requests\PenalizacionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PenalizacionController extends Controller
{
    public function index(Request $r, ConsultarPenalizaciones $q): JsonResponse
    {
        return response()->json($q->listing($r->user()->id, (string) $r->input('estado', '')));
    }

    public function configuration(PenalizacionRequest $r, GestionarPenalizaciones $s): JsonResponse
    {
        return response()->json(['data' => $s->configure($r->user()->id, $r->payload())]);
    }

    public function recalculate(PenalizacionRequest $r, int $productor, GestionarPenalizaciones $s): JsonResponse
    {
        $s->recalculate($r->user()->id, $productor, (string) $r->input('motivo', ''));

        return response()->json(['message' => 'Evaluación controlada completada.']);
    }

    public function decide(PenalizacionRequest $r, string $uuid, GestionarPenalizaciones $s): JsonResponse
    {
        return response()->json(['data' => $s->decide($r->user()->id, $uuid, $r->payload())]);
    }

    public function annul(PenalizacionRequest $r, string $uuid, GestionarPenalizaciones $s): JsonResponse
    {
        $s->annul($r->user()->id, $uuid, (string) $r->input('motivo', ''));

        return response()->json(['message' => 'Sanción anulada con auditoría.']);
    }

    public function assistances(Request $r, ConsultarPenalizaciones $q): JsonResponse
    {
        return response()->json($q->assistances($r->user()->id));
    }

    public function assistance(PenalizacionRequest $r, string $uuid, GestionarPenalizaciones $s): JsonResponse
    {
        return response()->json(['data' => $s->assistance($r->user()->id, $uuid, $r->payload())]);
    }
}
