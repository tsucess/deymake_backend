<?php

namespace Database\Factories;

use App\Models\Gift;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Gift>
 */
class GiftFactory extends Factory
{
    protected $model = Gift::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Rose', 'Heart', 'Diamond', 'Lion', 'Crown', 'Rocket', 'Star', 'Africa'])
            .' '.fake()->unique()->numerify('##');
        $coinCost = fake()->numberBetween(1, 500);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'icon' => fake()->randomElement(['🌹', '❤️', '💎', '🦁']),
            'image_url' => null,
            'coin_cost' => $coinCost,
            'price_amount' => $coinCost * 100,
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
