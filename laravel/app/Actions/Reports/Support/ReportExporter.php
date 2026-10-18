<?php

namespace App\Actions\Reports\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lightweight report exports (CSV + DomPDF). No maatwebsite/excel in v1.
 */
class ReportExporter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function respond(array $payload, string $format, string $basename): JsonResponse|StreamedResponse|Response
    {
        return match (strtolower($format)) {
            'csv' => $this->csv($payload, $basename),
            'pdf' => $this->pdf($payload, $basename),
            default => response()->json(['data' => $payload]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function csv(array $payload, string $basename): StreamedResponse
    {
        $filename = $basename.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            $rows = $this->tabularRows($payload);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function pdf(array $payload, string $basename): Response
    {
        $rows = $this->tabularRows($payload);
        $headers = array_shift($rows) ?? [];
        $pdf = Pdf::loadView('pdf.report', [
            'title' => str_replace('-', ' ', $basename),
            'headers' => $headers,
            'rows' => $rows,
            'generatedAt' => now()->timezone(config('app.timezone'))->toDateTimeString(),
        ]);

        return $pdf->download($basename.'-'.now()->format('Ymd-His').'.pdf');
    }

    /**
     * Flatten a report payload into CSV/PDF rows. Prefers `rows` + column keys from
     * the first row; otherwise dumps scalar summary keys.
     *
     * @param  array<string, mixed>  $payload
     * @return list<list<string|int|float|null>>
     */
    public function tabularRows(array $payload): array
    {
        if (isset($payload['rows']) && is_array($payload['rows']) && $payload['rows'] !== []) {
            $first = $payload['rows'][0];
            $keys = array_keys(is_array($first) ? $first : []);
            $table = [$keys];
            foreach ($payload['rows'] as $row) {
                $table[] = array_map(
                    fn ($key) => is_array($row[$key] ?? null) ? json_encode($row[$key]) : ($row[$key] ?? ''),
                    $keys,
                );
            }
            if (isset($payload['totals']) && is_array($payload['totals'])) {
                $totalRow = [];
                foreach ($keys as $i => $key) {
                    $totalRow[] = $i === 0
                        ? 'TOTAL'
                        : ($payload['totals'][$key] ?? '');
                }
                $table[] = $totalRow;
            }

            return $table;
        }

        $keys = array_keys(array_filter($payload, fn ($v) => ! is_array($v)));
        $table = [$keys, array_map(fn ($k) => $payload[$k] ?? '', $keys)];

        return $table;
    }
}
