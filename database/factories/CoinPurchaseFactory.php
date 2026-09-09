<?php

namespace Database\Factories;

use App\Models\CoinPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoinPurchase>
 */
class CoinPurchaseFactory extends Factory
{
    protected $model = CoinPurchase::class;

    public function definition(): array
    {
        $coins = fake()->numberBetween(70, 5000);

        return [
            'user_id' => User::factory(),
            'coin_package_id' => null,
            'coins' => $coins,
            'amount' => $coins * 100,
            'currency' => 'NGN',
            'status' => 'completed',
            'payment_provider' => fake()->randomElement(['paystack', 'flutterwave']),
            'payment_reference' => strtoupper(fake()->unique()->bothify('REF-####??')),
            'is_flagged' => false,
            'flag_reason' => null,
            'metadata' => null,
            'purchased_at' => fake()->dateTimeBetween('-20 days', 'now'),
        ];
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'refunded',
            'refunded_at' => now(),
        ]);
    }

    public function flagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_flagged' => true,
            'flag_reason' => 'Suspicious activity',
        ]);
    }
}
