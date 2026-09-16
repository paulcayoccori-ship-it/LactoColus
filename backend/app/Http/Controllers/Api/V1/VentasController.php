<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Inventario\GestionarInventario;
use App\Application\Ventas\ConsultarVentas;
use App\Application\Ventas\GestionarVentas;
use App\Http\Controllers\Controller;
use App\Http\Requests\VentaRequest;
use App\Http\Resources\VentaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VentasController extends Controller
{
    public function index(Request $request, ConsultarVentas $query): AnonymousResourceCollection
    {
        return VentaResource::collection($query->listing($request->user()->id, $request->only(['estado', 'cliente_id'])));
    }

    public function show(Request $request, string $uuid, ConsultarVentas $query): VentaResource
    {
        return new VentaResource($query->find($request->user()->id, $uuid));
    }

    public function store(VentaRequest $request, GestionarVentas $service): JsonResponse
    {
        $sale = $service->create($request->user()->id, $request->payload());

        return response()->json(['data' => new VentaResource($sale->load('detalles'))], $sale->wasRecentlyCreated ? 201 : 200);
    }

    public function action(VentaRequest $request, string $uuid, string $action, GestionarVentas $service): VentaResource
    {
        $actor = $request->user()->id;
        $data = $request->payload();
        $sale = match ($action) {
            'confirmar' => $service->confirm($actor, $uuid),'pagar' => $service->pay($actor, $uuid, $data),'anular' => $service->annul($actor, $uuid, (string) ($data['motivo'] ?? '')),default => abort(404)
        };

        return new VentaResource($sale);
    }

    public function options(Request $request, ConsultarVentas $query): JsonResponse
    {
        return response()->json(['data' => $query->options($request->user()->id)]);
    }

    public function movements(Request $request, ConsultarVentas $query): JsonResponse
    {
        return response()->json($query->movements($request->user()->id));
    }

    public function customer(VentaRequest $request, GestionarVentas $service, ?int $id = null): JsonResponse
    {
        return response()->json(['data' => $service->customer($request->user()->id, $request->payload(), $id)]);
    }

    public function pricing(VentaRequest $request, GestionarVentas $service): JsonResponse
    {
        $data = $request->payload();

        return response()->json(['data' => $service->pricing($request->user()->id, $data, (string) ($data['motivo'] ?? ''))]);
    }

    public function adjust(VentaRequest $request, GestionarInventario $service): JsonResponse
    {
        return response()->json(['data' => $service->adjust($request->user()->id, $request->payload())]);
    }
}
