<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RenderReceiptPdf;
use App\Actions\Payments\VoidPayment;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPaymentsRequest;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Requests\Api\V1\VoidPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    use PaginatesApiLists;

    public function index(ListPaymentsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = Payment::query()
            ->with('customer')
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        foreach (['customer_id', 'method', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('paid_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (isset($filters['to'])) {
            $query->where('paid_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return PaymentResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(StorePaymentRequest $request, RecordPayment $recordPayment): JsonResponse
    {
        $payment = $recordPayment->execute($request->user(), $request->validated());

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment->load(['customer', 'allocations.sale', 'receivedBy']));
    }

    public function void(VoidPaymentRequest $request, Payment $payment, VoidPayment $voidPayment): PaymentResource
    {
        return new PaymentResource($voidPayment->execute($request->user(), $payment, $request->validated('reason')));
    }

    public function receipt(Payment $payment, RenderReceiptPdf $renderReceipt): Response
    {
        $this->authorize('receipt', $payment);

        return $renderReceipt->execute($payment)->download($renderReceipt->filename($payment));
    }
}
