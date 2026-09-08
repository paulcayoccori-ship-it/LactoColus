<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Productores\ActualizarProductor;
use App\Application\Productores\ConsultarProductor;
use App\Application\Productores\CrearProductor;
use App\Application\Productores\EliminarProductor;
use App\Application\Productores\ListarProductores;
use App\Application\Productores\ProductorData;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProductorRequest;
use App\Http\Requests\ProductorRequest;
use App\Http\Resources\ProductorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductorController extends Controller
{
    public function index(IndexProductorRequest $request, ListarProductores $useCase): AnonymousResourceCollection
    {
        return ProductorResource::collection($useCase->handle((string) $request->input('search', ''), $request->has('estado') ? $request->boolean('estado') : null, $request->integer('per_page', 15)))->additional(['message' => 'Productores consultados.']);
    }

    public function store(ProductorRequest $request, CrearProductor $useCase): JsonResponse
    {
        return (new ProductorResource($useCase->handle(ProductorData::fromArray($request->validated()))))->additional(['message' => 'Productor creado.'])->response()->setStatusCode(201);
    }

    public function show(int $productor, ConsultarProductor $useCase): ProductorResource
    {
        return (new ProductorResource($useCase->handle($productor)))->additional(['message' => 'Productor consultado.']);
    }

    public function update(ProductorRequest $request, int $productor, ActualizarProductor $useCase): ProductorResource
    {
        return (new ProductorResource($useCase->handle($productor, ProductorData::fromArray($request->validated()))))->additional(['message' => 'Productor actualizado.']);
    }

    public function destroy(int $productor, EliminarProductor $useCase): Response
    {
        $useCase->handle($productor);

        return response()->noContent();
    }
}
