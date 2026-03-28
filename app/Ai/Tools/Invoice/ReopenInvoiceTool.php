<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReopenInvoiceTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Reopen a sent invoice for editing by reverting it to draft status, while keeping the same invoice number.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this before making any edits (adding/removing line items, changing dates)
        on an invoice that has already been sent (status="sent").

        After editing, call GenerateInvoicePdfTool to regenerate the PDF — this will
        mark the invoice as sent again.

        Do NOT call this on a paid or cancelled invoice — those statuses cannot be
        reverted and will return an error.
        Do NOT call this on a draft invoice — it is already editable.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        invoice_id vs invoice_number:
          - invoice_id is the integer DB primary key (preferred).
          - invoice_number is the human-readable string e.g. "INV-20260321-12345".
          - If both are provided, invoice_number takes precedence.
          - At least one must be provided.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Reopen by invoice_number:
          Input:  { "invoice_number": "INV-20260321-12345", "invoice_id": 99 }
          Output: { "success": true, "status": "draft",
                    "message": "Invoice INV-20260321-12345 reopened as draft." }

        Already paid — cannot reopen:
          Input:  { "invoice_id": 99 }
          Output: { "error": "Cannot reopen a paid invoice." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service   = new InvoiceAgentService($this->companyId);
            $invoiceId = $this->resolveInvoiceId($request);
            $result    = $service->reopenInvoice($invoiceId);

            return json_encode(['success' => true, ...$result]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    private function resolveInvoiceId(Request $request): int
    {
        if (! empty($request['invoice_number'])) {
            $invoice = \App\Models\Invoice::where('company_id', $this->companyId)
                ->where('invoice_number', trim($request['invoice_number']))
                ->firstOrFail();
            return $invoice->id;
        }

        return (int) $request['invoice_id'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_number' => $schema->string()
                ->description('Invoice number e.g. INV-20260321-12345. Preferred over invoice_id.'),

            'invoice_id' => $schema->integer()
                ->description('The invoice_id to reopen.')
                ->required(),
        ];
    }
}
