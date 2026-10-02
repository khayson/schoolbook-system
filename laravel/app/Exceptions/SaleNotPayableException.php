<?php

namespace App\Exceptions;

/**
 * An allocation names a sale that cannot take money: unknown, another customer's,
 * not confirmed, or already fully paid.
 */
class SaleNotPayableException extends ApiDomainException
{
    public const NOT_FOUND = 'not_found';

    public const OTHER_CUSTOMER = 'other_customer';

    public const NOT_CONFIRMED = 'not_confirmed';

    public const FULLY_PAID = 'fully_paid';

    public function __construct(int $saleId, string $reason, ?string $status = null)
    {
        $messages = [
            self::NOT_FOUND => 'The sale does not exist.',
            self::OTHER_CUSTOMER => 'The sale belongs to a different customer.',
            self::NOT_CONFIRMED => 'Only confirmed sales can receive payments.',
            self::FULLY_PAID => 'The sale is already fully paid.',
        ];

        parent::__construct(
            message: $messages[$reason] ?? 'The sale cannot receive payments.',
            errorCode: 'sale_not_payable',
            status: 422,
            details: array_filter([
                'sale_id' => $saleId,
                'reason' => $reason,
                'status' => $status,
            ], fn ($value) => $value !== null),
        );
    }
}
