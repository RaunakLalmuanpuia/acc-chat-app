<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchInvoicesTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Search and list invoices for this company with optional filters for status, date range, amount range, or client/invoice number.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call with NO parameters when the user says "show all invoices", "list my invoices",
        or any general listing request — do not add status or other filters unless the
        user explicitly named them.

        Call with status only when the user's message contains one of these exact words:
        "draft", "sent", "paid", "cancelled", "void", or "unpaid" (unpaid maps to sent).

        Passing status="draft" for a general list request is a critical error that hides
        data from the user — when in doubt, omit the status filter entirely.

        Do NOT use this to get a single invoice's line items — use GetInvoiceTool.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        query (optional):
          - Searches invoice_number fragments and client name. Omit if the user did
            not mention a specific client or invoice number.

        status (optional — see WHEN TO USE above):
          - Allowed values: draft, sent, paid, cancelled, void.
          - "unpaid" maps to sent (sent = issued but not yet paid).
          - OMIT for general list requests — adding status hides non-matching invoices.

        date_from / date_to:
          - Invoice date range in YYYY-MM-DD. Omit entirely (do NOT pass empty string)
            if the user did not specify dates.

        due_date_from / due_date_to:
          - Due date range in YYYY-MM-DD. Use to find overdue invoices.

        amount_min / amount_max:
          - Decimal total invoice amount bounds. Omit entirely (do NOT pass 0)
            if the user did not specify amounts.

        limit:
          - Max results (default 15, max 50).
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        List all invoices (no filters):
          Input:  {}
          Output: { "invoices": [...], "count": 12 }

        Show only paid invoices:
          Input:  { "status": "paid" }
          Output: { "invoices": [...], "count": 4 }

        Show invoices for a client:
          Input:  { "query": "Infosys" }
          Output: { "invoices": [...], "count": 3 }

        Show invoices between two dates:
          Input:  { "date_from": "2026-01-01", "date_to": "2026-03-31" }
          Output: { "invoices": [...], "count": 7 }

        WRONG — do not pass status for a general list:
          User:   "show me all my invoices"
          Input:  { "status": "draft" }  ← WRONG — hides sent/paid invoices
          Input:  {}                      ← CORRECT
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);

            $query  = strlen($request['query'] ?? '') > 0 ? trim($request['query']) : null;
            $status = strlen($request['status'] ?? '') > 0 ? trim($request['status']) : null;

            $amountMin = isset($request['amount_min']) && (float) $request['amount_min'] > 0
                ? (float) $request['amount_min']
                : null;

            $amountMax = isset($request['amount_max']) && (float) $request['amount_max'] > 0
                ? (float) $request['amount_max']
                : null;

            $hasDateFilter = ($request['date_from']     ?? '') !== ''
                || ($request['date_to']       ?? '') !== ''
                || ($request['due_date_from'] ?? '') !== ''
                || ($request['due_date_to']   ?? '') !== '';

            $isGeneralListRequest = $query === null
                && ! $hasDateFilter
                && $amountMin === null
                && $amountMax === null;

            if ($isGeneralListRequest && $status !== null) {
                $status = null;
            }

            \Log::info('[SearchInvoicesTool] resolved params', [
                'raw_status'       => $request['status']    ?? 'NOT SET',
                'effective_status' => $status               ?? 'NULL',
                'effective_query'  => $query                ?? 'NULL',
                'is_general_list'  => $isGeneralListRequest,
                'companyId'        => $this->companyId,
            ]);

            $invoices = $service->searchInvoices(
                query:       $query,
                status:      $status,
                dateFrom:    ($request['date_from']     ?? '') !== '' ? $request['date_from']     : null,
                dateTo:      ($request['date_to']       ?? '') !== '' ? $request['date_to']       : null,
                dueDateFrom: ($request['due_date_from'] ?? '') !== '' ? $request['due_date_from'] : null,
                dueDateTo:   ($request['due_date_to']   ?? '') !== '' ? $request['due_date_to']   : null,
                amountMin:   $amountMin,
                amountMax:   $amountMax,
                limit:       isset($request['limit']) ? min((int) $request['limit'], 50) : 15,
            );

            if (empty($invoices)) {
                return json_encode([
                    'invoices' => [],
                    'count'    => 0,
                    'message'  => 'No invoices found for this company.',
                ]);
            }

            return json_encode([
                'invoices' => $invoices,
                'count'    => count($invoices),
            ]);
        } catch (\Throwable $e) {
            \Log::error('[SearchInvoicesTool] exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Invoice number fragment or client name. Omit if not mentioned by user.'),

            'status' => $schema->string()
                ->description(
                    'ONLY pass when user explicitly says: draft, sent, paid, cancelled, void, or unpaid. ' .
                    'Omit for general list requests — passing status hides non-matching invoices.'
                )
                ->enum(['draft', 'sent', 'paid', 'cancelled', 'void']),

            'date_from'     => $schema->string()->description('Invoice date range start (YYYY-MM-DD). Omit if not specified.'),
            'date_to'       => $schema->string()->description('Invoice date range end (YYYY-MM-DD). Omit if not specified.'),
            'due_date_from' => $schema->string()->description('Due date range start (YYYY-MM-DD).'),
            'due_date_to'   => $schema->string()->description('Due date range end (YYYY-MM-DD).'),
            'amount_min'    => $schema->number()->description('Minimum total amount. Omit if not specified.'),
            'amount_max'    => $schema->number()->description('Maximum total amount. Omit if not specified.'),
            'limit'         => $schema->integer()->description('Max results. Defaults to 15.'),
        ];
    }
}
