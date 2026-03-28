<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddLineItemTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Add a line item to a draft invoice, auto-filling description, HSN code, unit, and GST rate when an inventory_item_id is supplied.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this after CreateInvoiceTool has returned an invoice_id, once for each
        product or service line the user wants on the invoice.

        When the item exists in inventory, call LookupInventoryItemTool first to get the
        inventory_item_id — passing it here auto-fills all catalogue fields and prevents
        typos in HSN codes and GST rates.

        When adding an ad-hoc line item not in inventory, pass description, hsn_code,
        unit, and gst_rate manually.

        Do NOT call this on a sent or paid invoice — use ReopenInvoiceTool first.
        Do NOT add a line item with quantity=0 to simulate a removal — use RemoveLineItemTool.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        invoice_id (required):
          - Integer from CreateInvoiceTool (or GetActiveDraftsTool). This is the DB primary
            key, NOT the human-readable invoice_number like "INV-20260325-101".

        inventory_item_id (optional but recommended):
          - Auto-fills description, hsn_code, unit, and gst_rate from the catalogue.
          - Obtain from LookupInventoryItemTool before calling this.

        rate (required):
          - Unit selling price before discount and GST. Always pass this explicitly —
            do not assume the catalogue rate is correct without confirming with the user.

        gst_rate:
          - Percentage (e.g. 18 for 18%). Required when inventory_item_id is NOT provided.
          - GST is automatically split into CGST+SGST (intra-state) or IGST (inter-state)
            based on the client's state vs the company's state.

        discount_percent:
          - Applied before GST calculation. Defaults to 0.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Add an inventory item:
          Input:  { "invoice_id": 101, "inventory_item_id": 7, "quantity": 3, "rate": 5000 }
          Output: { "success": true, "message": "Line item added. Invoice total is now INR 17700.00.",
                    "invoice": { "total_amount": "17700.00", "line_items": [...] } }

        Add an ad-hoc line item:
          Input:  { "invoice_id": 101, "description": "Consulting — March 2026",
                    "quantity": 10, "rate": 2000, "unit": "Hr",
                    "hsn_code": "998313", "gst_rate": 18 }
          Output: { "success": true, "message": "Line item added. Invoice total is now INR 23600.00.", ... }

        Add with discount:
          Input:  { "invoice_id": 101, "inventory_item_id": 8,
                    "quantity": 5, "rate": 299, "discount_percent": 10 }
          Output: { "success": true, "message": "Line item added. Invoice total is now INR 1588.85.", ... }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);

            $invoice = $service->addLineItem(
                invoiceId:       $request['invoice_id'],
                quantity:        (float) $request['quantity'],
                rate:            (float) $request['rate'],
                inventoryItemId: isset($request['inventory_item_id']) ? (int) $request['inventory_item_id'] : null,
                description:     $request['description']     ?? null,
                hsnCode:         $request['hsn_code']        ?? null,
                unit:            $request['unit']            ?? null,
                discountPercent: (float) ($request['discount_percent'] ?? 0),
                gstRate:         isset($request['gst_rate']) ? (float) $request['gst_rate'] : null,
            );

            return json_encode([
                'success' => true,
                'invoice' => $invoice,
                'message' => "Line item added. Invoice total is now {$invoice['currency']} {$invoice['total_amount']}.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('The invoice_id (DB primary key) returned when the draft was created.')
                ->required(),

            'quantity' => $schema->number()
                ->description('Quantity of the item.')
                ->required(),

            'rate' => $schema->number()
                ->description('Unit price (before discount) of the item.')
                ->required(),

            'inventory_item_id' => $schema->integer()
                ->description('Optional: ID from LookupInventoryItemTool. Auto-fills description, HSN, unit, and GST rate.'),

            'description' => $schema->string()
                ->description('Line item description. Required if inventory_item_id is not provided.'),

            'hsn_code' => $schema->string()
                ->description('HSN/SAC code for GST. Auto-filled if inventory_item_id is provided.'),

            'unit' => $schema->string()
                ->description('Unit of measure, e.g. "Nos", "Kg", "Hr". Auto-filled if inventory_item_id is provided.'),

            'discount_percent' => $schema->number()
                ->description('Discount percentage (0–100). Defaults to 0.'),

            'gst_rate' => $schema->number()
                ->description('GST rate percentage, e.g. 18 for 18% GST. Auto-filled if inventory_item_id is provided.'),
        ];
    }
}
