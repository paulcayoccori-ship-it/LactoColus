<?php

declare(strict_types=1);

namespace App\Infrastructure\Productores;

use App\Domain\Productores\ProductorRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class EloquentProductorRepository implements ProductorRepository
{
    public function paginate(string $search, ?bool $estado, int $perPage): LengthAwarePaginator
    {
        return Productor::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    foreach (['codigo', 'dni', 'nombres', 'apellidos', 'comunidad'] as $field) {
                        $query->orWhere($field, 'like', '%'.$search.'%');
                    }
                });
            })
            ->when($estado !== null, fn (Builder $query) => $query->where('estado', $estado))
            ->orderByDesc('id')->paginate($perPage)->withQueryString()
            ->through(fn (Productor $productor): array => $productor->toArray());
    }

    public function find(int $id): array
    {
        return Productor::query()->findOrFail($id)->toArray();
    }

    public function create(array $attributes): array
    {
        try {
            return Productor::query()->create($attributes)->refresh()->toArray();
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['codigo' => 'El código, DNI o correo ya está registrado.']);
        }
    }

    public function update(int $id, array $attributes): array
    {
        $productor = Productor::query()->findOrFail($id);
        try {
            $productor->update($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['codigo' => 'El código, DNI o correo ya está registrado.']);
        }

        return $productor->refresh()->toArray();
    }

    public function delete(int $id): void
    {
        Productor::query()->findOrFail($id)->delete();
    }
}
