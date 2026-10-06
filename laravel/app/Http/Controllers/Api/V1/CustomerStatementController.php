<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\CustomerStatement;
use App\Actions\Customers\RenderStatementPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerStatementRequest;
use App\Http\Resources\ReportResource;
use App\Models\Customer;
use Symfony\Component\HttpFoundation\Response;

class CustomerStatementController extends Controller
{
    public function show(CustomerStatementRequest $request, Customer $customer, CustomerStatement $statement, RenderStatementPdf $renderPdf): ReportResource|Response
    {
        $from = $request->validated('from');
        $to = $request->validated('to');

        if ($request->validated('format') === 'pdf') {
            return $renderPdf->execute($customer, $from, $to)->download($renderPdf->filename($customer, $from, $to));
        }

        return new ReportResource($statement->run($customer, $from, $to));
    }
}
