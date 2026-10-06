<?php

namespace App\Actions\Schools;

use App\Actions\Customers\CreateCustomer;
use App\Enums\CustomerType;
use App\Exceptions\ApiDomainException;
use App\Models\Customer;
use App\Models\DirectorySchool;
use Illuminate\Support\Facades\DB;

/**
 * Makes a directory school a customer: a new customer prefilled from the directory (name,
 * type school, region, district, phone), or, with $linkCustomerId, an existing customer
 * that is that school. The directory entry is then linked, so it shows as "added".
 *
 * Refused (409): `already_customer` when the entry is already linked;
 * `customer_name_exists` when a customer with the same name exists (the client may link
 * it instead); `customer_already_linked` when that customer is linked to another entry.
 */
class AddDirectorySchoolAsCustomer
{
    public function __construct(private readonly CreateCustomer $createCustomer) {}

    /**
     * @param  array{contact_person?: string|null, phone?: string|null, email?: string|null, credit_limit?: int|null}  $details
     */
    public function execute(DirectorySchool $school, array $details = [], ?int $linkCustomerId = null): Customer
    {
        return DB::transaction(function () use ($school, $details, $linkCustomerId) {
            $locked = DirectorySchool::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
            if ($locked->customer_id !== null) {
                throw new ApiDomainException(
                    "{$locked->name} is already a customer.",
                    'already_customer',
                    409,
                    ['customer_id' => $locked->customer_id],
                );
            }

            if ($linkCustomerId !== null) {
                $customer = Customer::query()->findOrFail($linkCustomerId);
                if (DirectorySchool::query()->where('customer_id', $customer->id)->exists()) {
                    throw new ApiDomainException(
                        "{$customer->name} is already linked to another directory school.",
                        'customer_already_linked',
                        409,
                        ['customer_id' => $customer->id],
                    );
                }
            } else {
                $same = Customer::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($locked->name)])->first();
                if ($same !== null) {
                    throw new ApiDomainException(
                        "A customer named {$same->name} already exists ({$same->code}). Link it instead of adding a second one.",
                        'customer_name_exists',
                        409,
                        ['customer_id' => $same->id, 'code' => $same->code, 'name' => $same->name],
                    );
                }
                $customer = $this->createCustomer->execute([
                    'name' => $locked->name,
                    'type' => CustomerType::School->value,
                    'region' => $locked->region,
                    'district' => $locked->district,
                    'address' => $locked->town,
                    'contact_person' => $details['contact_person'] ?? null,
                    'phone' => ($details['phone'] ?? null) ?: $locked->phone,
                    'email' => $details['email'] ?? null,
                    'credit_limit' => $details['credit_limit'] ?? null,
                ]);
            }

            $locked->update(['customer_id' => $customer->id]);

            return $customer;
        });
    }
}
