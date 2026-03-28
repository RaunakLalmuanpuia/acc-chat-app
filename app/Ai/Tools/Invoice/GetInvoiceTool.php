<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetInvoiceTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Retrieve the current state of an invoice including all line items, GST breakdown, and totals.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this to review a draft before generating the PDF or finalizing.
        Call this before RemoveLineItemTool to get the line_item_id values needed for removal.
        Call this when the user asks "what's on this invoice?" or "show me the invoice".

        Do NOT call this just to get the invoice_id — use GetActiveDraftsTool for that.
        WHEN;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Retrieve a draft:
          Input:  { "invoice_id": 101 }
          Output: { "invoice": { "id": 101, "invoice_number": "INV-20260325-101",
                    "status": "draft", "total_amount": "17700.00", "currency": "INR",
                    "line_items": [{ "id": 55, "description": "Web Design",
                      "quantity": 3, "rate": 5000, "gst_rate": 18,
                      "cgst": 1350, "sgst": 1350, "amount": 17700 }] } }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);
            $invoice = $service->getInvoice((int) $request['invoice_id']);

            return json_encode(['invoice' => $invoice]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('The invoice_id (DB primary key) to retrieve.')
                ->required(),
        ];
    }
}

