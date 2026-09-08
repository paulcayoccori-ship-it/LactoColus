<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Productores\Productor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Productor> */
class ProductorFactory extends Factory
{
    protected $model = Productor::class;

    public function definition(): array
    {
        return ['codigo' => fake()->unique()->bothify('PRO-######'), 'dni' => fake()->unique()->numerify('########'),
            'nombres' => fake()->firstName(), 'apellidos' => fake()->lastName(), 'celular' => fake()->numerify('9########'),
            'email' => fake()->unique()->safeEmail(), 'direccion' => fake()->streetAddress(), 'comunidad' => fake()->randomElement(['Santa Rosa', 'San Pedro', 'La Esperanza']), 'estado' => true];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['estado' => false]);
    }
}
