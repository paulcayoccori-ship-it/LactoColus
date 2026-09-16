<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Ranking\ConsultarRanking;
use App\Application\Ranking\GestionarRanking;
use App\Http\Controllers\Controller;
use App\Http\Requests\RankingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function index(Request $r, ConsultarRanking $q): JsonResponse
    {
        return response()->json(['data' => $q->publicData($r->only(['tipo', 'fecha', 'ruta_id']) + ['tipo' => 'diario', 'fecha' => today()->toDateString()])]);
    }

    public function configure(RankingRequest $r, GestionarRanking $s): JsonResponse
    {
        return response()->json(['data' => $s->configure($r->user()->id, $r->payload())]);
    }

    public function calculate(RankingRequest $r, GestionarRanking $s): JsonResponse
    {
        $run = $s->calculate($r->user()->id, $r->payload());

        return response()->json(['data' => ['uuid' => $run->uuid, 'desde' => $run->desde->toDateString(), 'hasta' => $run->hasta->toDateString(), 'algoritmo' => $run->algoritmo]], $run->wasRecentlyCreated ? 201 : 200);
    }
}
