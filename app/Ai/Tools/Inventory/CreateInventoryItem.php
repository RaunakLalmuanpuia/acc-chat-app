<?php

namespace App\Ai\Tools\Inventory;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateInventoryItem extends BaseTool
{
    public function __construct(protected User $user) {}

    protected function purpose(): string
    {
        return 'Create a new product or service item in the inventory catalogue.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to add a new product or service that does not yet
        exist in inventory, so it can be used as a line item on future invoices.

        Do NOT call this without first calling GetInventory to check the item doesn't
        already exist — duplicate catalogue entries cause confusion at invoicing time.
        Do NOT call this to update an existing item's price or stock — there is no
        UpdateInventoryItem tool yet; inform the user if they ask.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        name (required):
          - The display name shown on invoices.

        rate (required):
          - Selling price per unit (before GST). Decimal number.

        gst_rate:
          - Percentage, e.g. 18 for 18% GST. Defaults to 0.
          - Common Indian GST slabs: 0, 5, 12, 18, 28.

        unit:
          - Unit of measure shown on the invoice line. Defaults to "Nos".
          - Common values: Nos, Kg, g, L, ml, Hr, Day, Pc, Box, Set.

        hsn_code:
          - 4–8 digit HSN (goods) or SAC (services) code for GST filing.
          - Leave blank if unknown; it can be added later.

        track_stock / stock_quantity / low_stock_alert:
          - Set track_stock=true to enable inventory tracking.
          - stock_quantity is the opening balance (integer, default 0).
          - low_stock_alert triggers a warning when stock falls to or below this level.

        sku:
          - Must be unique across all items for this company. The tool returns an error
            if a duplicate SKU is detected.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Create a service item:
          Input:  { "name": "Web Design", "rate": 5000, "unit": "Hr",
                    "gst_rate": 18, "hsn_code": "998314" }
          Output: { "success": true, "item_id": 7, "item_name": "Web Design", "rate": 5000 }

        Create a physical product with stock tracking:
          Input:  { "name": "USB-C Cable", "rate": 299, "unit": "Nos",
                    "gst_rate": 18, "hsn_code": "8544", "sku": "CBL-USBC-1M",
                    "track_stock": true, "stock_quantity": 100, "low_stock_alert": 10 }
          Output: { "success": true, "item_id": 8, "item_name": "USB-C Cable", "rate": 299 }

        Duplicate SKU:
          Input:  { "name": "USB-C Cable v2", "rate": 349, "sku": "CBL-USBC-1M" }
          Output: { "success": false, "message": "An item with SKU \"CBL-USBC-1M\" already exists." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'Please set up your company profile first.']);
        }

        if (! empty($request['sku'])) {
            $skuExists = $company->inventoryItems()->where('sku', $request['sku'])->exists();
            if ($skuExists) {
                return json_encode([
                    'success' => false,
                    'message' => "An item with SKU \"{$request['sku']}\" already exists.",
                ]);
            }
        }

        $item = $company->inventoryItems()->create([
            'name'            => $request['name'],
            'sku'             => $request['sku']             ?? null,
            'description'     => $request['description']     ?? null,
            'category'        => $request['category']        ?? null,
            'brand'           => $request['brand']           ?? null,
            'unit'            => $request['unit']            ?? 'Nos',
            'hsn_code'        => $request['hsn_code']        ?? null,
            'gst_rate'        => $request['gst_rate']        ?? 0,
            'rate'            => $request['rate'],
            'cost_price'      => $request['cost_price']      ?? null,
            'mrp'             => $request['mrp']             ?? null,
            'track_stock'     => $request['track_stock']     ?? false,
            'stock_quantity'  => $request['stock_quantity']  ?? 0,
            'low_stock_alert' => $request['low_stock_alert'] ?? 0,
            'is_active'       => true,
        ]);

        return json_encode([
            'success'   => true,
            'message'   => "Item \"{$item->name}\" created successfully.",
            'item_id'   => $item->id,
            'item_name' => $item->name,
            'rate'      => $item->rate,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name'            => $schema->string()->required(),
            'rate'            => $schema->number()->required(),
            'sku'             => $schema->string(),
            'description'     => $schema->string(),
            'category'        => $schema->string(),
            'brand'           => $schema->string(),
            'unit'            => $schema->string(),
            'hsn_code'        => $schema->string(),
            'gst_rate'        => $schema->number()->min(0)->max(100),
            'cost_price'      => $schema->number(),
            'mrp'             => $schema->number(),
            'track_stock'     => $schema->boolean(),
            'stock_quantity'  => $schema->integer()->min(0),
            'low_stock_alert' => $schema->integer()->min(0),
        ];
    }
}
