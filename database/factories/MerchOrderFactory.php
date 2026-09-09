<?php

namespace Database\Factories;

use App\Models\MerchOrder;
use App\Models\MerchProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchOrder>
 */
class MerchOrderFactory extends Factory
{
    protected $model = MerchOrder::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->numberBetween(1_000, 200_000);

        return [
            'merch_product_id' => MerchProduct::factory(),
            'creator_id' => User::factory(),
            'buyer_id' => User::factory(),
            'quantity' => $quantity,
            'unit_price_amount' => $unitPrice,
            'total_amount' => $quantity * $unitPrice,
            'currency' => 'NGN',
            'discount_code' => null,
            'discount_amount' => 0,
            'status' => 'paid',
            'shipping_address' => null,
            'notes' => null,
            'placed_at' => fake()->dateTimeBetween('-20 days', 'now'),
            'fulfilled_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'fulfilled',
            'fulfilled_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }
}
