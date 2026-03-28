<?php

namespace App\Ai\Tools\Inventory;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateInventoryItem extends BaseTool
{
    public function __construct(protected User $user) {}

    protected function purpose(): string
    {
        return 'Update one or more fields on an existing inventory item, including its price, GST rate, stock settings, and active status.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to change a product's rate, HSN code, GST rate,
        description, category, or stock settings.

        Use adjust_stock (positive or negative integer) to add or subtract from the
        current stock quantity rather than overwriting it — prefer this over stock_quantity
        unless setting an absolute stock reset.

        Do NOT call this to create an item — use CreateInventoryItem.
        Do NOT call this to delete an item — use DeleteInventoryItem.
        Do NOT pass fields that should not change.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        item_id (required):
          - Integer DB primary key from GetInventory or LookupInventoryItemTool.

        adjust_stock vs stock_quantity:
          - adjust_stock: relative adjustment — adds or subtracts from current quantity.
            Use for "add 50 units" or "remove 10 units". Floors at 0 (cannot go negative).
          - stock_quantity: absolute override — sets the quantity directly.
            Use only for a full stock count/reset.
          - Do NOT pass both in the same call.

        gst_rate:
          - Percentage (0–100). Common Indian slabs: 0, 5, 12, 18, 28.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Update selling rate:
          Input:  { "item_id": 7, "rate": 5500 }
          Output: { "success": true, "message": "Item \"Web Design\" updated successfully.",
                    "updated_fields": ["rate"], "current_stock": 0 }

        Add stock (relative adjustment):
          Input:  { "item_id": 8, "adjust_stock": 50 }
          Output: { "success": true, "updated_fields": ["stock_quantity"], "current_stock": 150 }

        Remove stock (relative adjustment):
          Input:  { "item_id": 8, "adjust_stock": -10 }
          Output: { "success": true, "updated_fields": ["stock_quantity"], "current_stock": 140 }

        Deactivate an item:
          Input:  { "item_id": 8, "is_active": false }
          Output: { "success": true, "updated_fields": ["is_active"], "current_stock": 140 }

        Nothing to update:
          Input:  { "item_id": 8 }
          Output: { "success": false, "message": "No fields provided to update." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $item = $company->inventoryItems()->find($request['item_id']);

        if (! $item) {
            return json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        }

        $updatable = [
            'name', 'sku', 'description', 'category', 'brand', 'unit',
            'hsn_code', 'gst_rate', 'rate', 'cost_price', 'mrp',
            'track_stock', 'stock_quantity', 'low_stock_alert', 'is_active',
        ];

        $updates = [];
        foreach ($updatable as $field) {
            if (isset($request[$field])) {
                $updates[$field] = $request[$field];
            }
        }

        if (isset($request['adjust_stock'])) {
            $updates['stock_quantity'] = max(0, $item->stock_quantity + (int) $request['adjust_stock']);
        }

        if (empty($updates)) {
            return json_encode(['success' => false, 'message' => 'No fields provided to update.']);
        }

        $item->update($updates);

        return json_encode([
            'success'        => true,
            'message'        => "Item \"{$item->name}\" updated successfully.",
            'updated_fields' => array_keys($updates),
            'current_stock'  => $item->fresh()->stock_quantity,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id'         => $schema->integer()->required(),
            'name'            => $schema->string(),
            'sku'             => $schema->string(),
            'description'     => $schema->string(),
            'category'        => $schema->string(),
            'brand'           => $schema->string(),
            'unit'            => $schema->string(),
            'hsn_code'        => $schema->string(),
            'gst_rate'        => $schema->number()->min(0)->max(100),
            'rate'            => $schema->number(),
            'cost_price'      => $schema->number(),
            'mrp'             => $schema->number(),
            'track_stock'     => $schema->boolean(),
            'stock_quantity'  => $schema->integer()->min(0),
            'adjust_stock'    => $schema->integer(),
            'low_stock_alert' => $schema->integer()->min(0),
            'is_active'       => $schema->boolean(),
        ];
    }
}
