<?php

namespace Database\Factories;

use App\Models\CoinPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CoinPackage>
 */
class CoinPackageFactory extends Factory
{
    protected $model = CoinPackage::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Pack';
        $coins = fake()->numberBetween(70, 5000);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'coins' => $coins,
            'bonus_coins' => fake()->randomElement([0, 0, 10, 50]),
            'price_amount' => $coins * 100,
            'currency' => 'NGN',
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
