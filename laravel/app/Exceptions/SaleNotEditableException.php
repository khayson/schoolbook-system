<?php

namespace App\Exceptions;

use App\Models\Sale;

/**
 * The sale's current status does not allow the requested action
 * (update, confirm, cancel, void, deliver, invoice).
 */
class SaleNotEditableException extends ApiDomainException
{
    private const PAST_TENSE = [
        'update' => 'updated',
        'confirm' => 'confirmed',
        'cancel' => 'cancelled',
        'void' => 'voided',
        'deliver' => 'marked as delivered',
        'invoice' => 'invoiced',
    ];

    public function __construct(Sale $sale, string $action = 'update')
    {
        $verb = self::PAST_TENSE[$action] ?? $action;

        parent::__construct(
            message: "This sale is {$sale->status->value} and cannot be {$verb}.",
            errorCode: 'sale_not_editable',
            status: 409,
            details: [
                'sale_id' => $sale->id,
                'status' => $sale->status->value,
                'action' => $action,
            ],
        );
    }
}
