<?php

namespace Database\Factories;

use App\Models\MerchProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchProduct>
 */
class MerchProductFactory extends Factory
{
    protected $model = MerchProduct::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);
        $price = fake()->numberBetween(1_000, 500_000);

        return [
            'creator_id' => User::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => 'active',
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'description' => fake()->sentence(12),
            'price_amount' => $price,
            'discount_amount' => 0,
            'currency' => 'NGN',
            'inventory_count' => fake()->numberBetween(0, 500),
            'images' => [fake()->imageUrl()],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'draft']);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'archived']);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => ['inventory_count' => 0]);
    }
}
