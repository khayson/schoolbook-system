<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Services\NumberSequenceService;
use Illuminate\Support\Facades\DB;

class CreateCustomer
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $number = $this->sequences->next('customer', 0);
            $code = $this->sequences->format('CUS', 0, $number, 4);

            $customer = Customer::query()->create([
                ...$data,
                'code' => $code,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $customer->credit_balance = 0;
            $customer->save();

            return $customer;
        });
    }
}
