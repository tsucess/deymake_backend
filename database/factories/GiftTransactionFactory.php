<?php

namespace Database\Factories;

use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GiftTransaction>
 */
class GiftTransactionFactory extends Factory
{
    protected $model = GiftTransaction::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 10);
        $unitCost = fake()->numberBetween(1, 100);
        $coinAmount = $quantity * $unitCost;

        return [
            'gift_id' => Gift::factory(),
            'gift_name' => fake()->randomElement(['Rose', 'Heart', 'Diamond', 'Lion']),
            'sender_id' => User::factory(),
            'recipient_id' => User::factory(),
            'video_id' => null,
            'quantity' => $quantity,
            'coin_amount' => $coinAmount,
            'creator_earnings' => $coinAmount * 50,
            'currency' => 'NGN',
            'status' => 'completed',
            'is_flagged' => false,
            'flag_reason' => null,
            'metadata' => null,
            'sent_at' => fake()->dateTimeBetween('-20 days', 'now'),
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
