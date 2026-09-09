<?php

namespace Database\Factories;

use App\Models\MerchOrder;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundRequest>
 */
class RefundRequestFactory extends Factory
{
    protected $model = RefundRequest::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(1000, 250000);

        return [
            'order_id' => MerchOrder::factory(),
            'user_id' => User::factory(),
            'amount' => $amount,
            'currency' => 'NGN',
            'reason' => fake()->sentence(),
            'status' => 'pending',
            'admin_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'processed_by' => null,
            'processed_at' => null,
            'payment_reference' => null,
            'provider_response' => null,
        ];
    }
}
