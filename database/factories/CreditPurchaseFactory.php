<?php

namespace Database\Factories;

use App\Models\CreditPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreditPurchaseFactory extends Factory
{
    protected $model = CreditPurchase::class;

    public function definition(): array
    {
        return [
            'package_name'      => 'Starter 500',
            'credits_amount'    => 500,
            'amount_paid'       => 49.99,
            'payment_intent_id' => 'pi_test_' . $this->faker->unique()->lexify('??????????????????'),
            'status'            => 'completed',
        ];
    }
}
