<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(50000, 5000000);
        $createdAt = fake()->dateTimeBetween('-20 days', 'now');

        return [
            'reference' => 'DMK_'.strtoupper(fake()->unique()->bothify('??########')),
            'provider' => 'paystack',
            'provider_reference' => strtoupper(fake()->unique()->bothify('PSK_########')),
            'user_id' => User::factory(),
            'email' => fake()->safeEmail(),
            'purpose' => fake()->randomElement(['coin_purchase', 'wallet_topup', 'membership', 'merch_order']),
            'purpose_type' => null,
            'purpose_id' => null,
            'amount' => $amount,
            'currency' => 'NGN',
            'status' => 'successful',
            'channel' => fake()->randomElement(['card', 'bank', 'bank_transfer', 'ussd']),
            'fees' => (int) round($amount * 0.015),
            'authorization_url' => null,
            'gateway_response' => 'Successful',
            'is_flagged' => false,
            'metadata' => null,
            'paid_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'provider_reference' => null,
            'channel' => null,
            'fees' => 0,
            'gateway_response' => null,
            'paid_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'channel' => null,
            'fees' => 0,
            'gateway_response' => 'Declined by financial institution',
            'paid_at' => null,
        ]);
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
