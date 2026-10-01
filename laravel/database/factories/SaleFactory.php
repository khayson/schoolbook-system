<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'invoice_no' => null,
            'customer_id' => Customer::factory(),
            'status' => SaleStatus::Draft,
            'payment_status' => PaymentStatus::Unpaid,
            'source' => SaleSource::Staff,
            'sale_date' => now()->toDateString(),
            'due_date' => null,
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'amount_paid' => 0,
            'balance_due' => 0,
            'notes' => null,
            'created_by' => User::factory()->owner(),
        ];
    }
}
