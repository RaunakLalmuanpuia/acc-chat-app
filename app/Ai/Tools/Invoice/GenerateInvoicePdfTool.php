<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GenerateInvoicePdfTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Render and save the invoice PDF to storage, persisting the PDF path on the invoice record and marking the invoice as sent.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this as the final step of the invoice workflow, after all line items have
        been added and reviewed with GetInvoiceTool.

        Always call GetInvoiceTool first to verify line items and totals are correct
        before generating.

        Do NOT call this on an invoice that still needs line items or edits — generation
        marks the invoice sent.
        Do NOT call this if the invoice is already in paid or cancelled status.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        invoice_id vs invoice_number:
          - invoice_id is the integer DB primary key (preferred — fastest resolution).
          - invoice_number is the human-readable string e.g. "INV-20260325-101".
          - If both are provided, invoice_number takes precedence.
          - At least one must be provided.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Generate by invoice_id:
          Input:  { "invoice_id": 101 }
          Output: { "success": true, "pdf_path": "invoices/INV-20260325-101.pdf",
                    "invoice_number": "INV-20260325-101" }

        Generate by invoice_number:
          Input:  { "invoice_number": "INV-20260325-101", "invoice_id": 101 }
          Output: { "success": true, "pdf_path": "invoices/INV-20260325-101.pdf" }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service   = new InvoiceAgentService($this->companyId);
            $invoiceId = $this->resolveInvoiceId($request);
            $result    = $service->generatePdf($invoiceId);

            return json_encode(['success' => true, ...$result]);
        } catch (\Throwable $e) {
            \Log::error('[GenerateInvoicePdfTool] Tool execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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
                ->description('Preferred: invoice number e.g. INV-20260311-57474. Use this instead of invoice_id when available.'),

            'invoice_id' => $schema->integer()
                ->description('The invoice_id (DB primary key) to generate the PDF for. Optional when invoice_number is provided.'),
        ];
    }
}
