<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class LookupInventoryItemTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Search inventory items by name, SKU, or HSN code, returning the rate, GST rate, unit, and HSN code needed to add a line item to an invoice.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this before AddLineItemTool whenever the user refers to a product or service
        by name (e.g. "add 3 units of Web Design"). The returned inventory_item_id lets
        AddLineItemTool auto-fill description, HSN, unit, and GST rate.

        Do NOT call this if the user is adding a completely ad-hoc line item with no
        catalogue entry — just pass description and fields directly to AddLineItemTool.
        Do NOT call this twice for the same item in one turn.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        query (required):
          - Item name fragment, SKU, HSN/SAC code, or numeric item ID as a string.
          - Partial name matches are supported.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Search by name:
          Input:  { "query": "Web Design" }
          Output: { "items": [{ "id": 7, "name": "Web Design", "rate": 5000,
                    "gst_rate": 18, "unit": "Hr", "hsn_code": "998314" }], "count": 1 }

        Search by SKU:
          Input:  { "query": "CBL-USBC-1M" }
          Output: { "items": [{ "id": 8, "name": "USB-C Cable", "rate": 299,
                    "gst_rate": 18, "unit": "Nos", "hsn_code": "8544" }], "count": 1 }

        Not found (item not in catalogue):
          Input:  { "query": "Invisible Widget" }
          Output: { "items": [], "count": 0,
                    "message": "No inventory items found matching 'Invisible Widget'. The item does not exist in the catalogue — add it as a manual line item or create it in inventory first." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $service = new InvoiceAgentService($this->companyId);
        $items   = $service->findInventoryItems($request['query']);

        if (empty($items)) {
            return json_encode([
                'items'   => [],
                'count'   => 0,
                'message' => "No inventory items found matching '{$request['query']}'. The item does not exist in the catalogue — add it as a manual line item or create it in inventory first.",
            ]);
        }

        return json_encode(['items' => $items, 'count' => count($items)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Item name fragment, SKU, HSN code, or numeric item ID to search for.')
                ->required(),
        ];
    }
}
