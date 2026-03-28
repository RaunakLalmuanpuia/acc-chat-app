<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class RemoveLineItemTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Remove a specific line item from a draft invoice by its line_item_id, recalculating all totals automatically.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to delete or remove a line from the invoice.

        Always call GetInvoiceTool first to get the correct line_item_id values —
        line_item_id is the per-line DB primary key, not the invoice_id or a position number.

        Do NOT add a zero-quantity line item to simulate removal — always use this tool.
        Do NOT call this on a sent or paid invoice — use ReopenInvoiceTool first.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        invoice_id (required):
          - Integer DB primary key of the invoice. NOT the invoice_number string.

        line_item_id (required):
          - Integer DB primary key of the specific line item to remove.
          - Obtain from the line_items array in GetInvoiceTool response.
          - Each line item has its own unique id — do not confuse with invoice_id.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Remove a line item (after GetInvoiceTool showed line item id=55):
          Input:  { "invoice_id": 101, "line_item_id": 55 }
          Output: { "success": true, "message": "Line item removed. Invoice total is now INR 0.00.",
                    "invoice": { "total_amount": "0.00", "line_items": [] } }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);
            $invoice = $service->removeLineItem(
                invoiceId:  (int) $request['invoice_id'],
                lineItemId: (int) $request['line_item_id'],
            );

            return json_encode([
                'success' => true,
                'invoice' => $invoice,
                'message' => "Line item removed. Invoice total is now {$invoice['currency']} {$invoice['total_amount']}.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('The invoice_id (DB primary key, not invoice_number).')
                ->required(),

            'line_item_id' => $schema->integer()
                ->description('The id of the line item to remove, from GetInvoiceTool results.')
                ->required(),
        ];
    }
}
