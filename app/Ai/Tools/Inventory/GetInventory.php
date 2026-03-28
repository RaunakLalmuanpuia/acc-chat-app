<?php

namespace App\Ai\Tools\Inventory;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetInventory extends BaseTool
{
    public function __construct(protected User $user) {}

    protected function purpose(): string
    {
        return 'List or search inventory items for the company, with optional filtering by name, SKU, category, active status, and low-stock threshold.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user asks to "show inventory", "list products", or wants to browse
        the item catalogue.
        Call this before CreateInventoryItem to confirm the item does not already exist.

        Do NOT call this to look up an item_id for invoice line items — use
        LookupInventoryItemTool, which is optimised for that workflow and returns the
        GST rate, HSN code, and unit in a single call.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        search (optional):
          - Partial text match across name, SKU, and category fields.
          - Omit to list all items (up to per_page, default 20).

        low_stock_only:
          - true → return only items whose stock_quantity <= low_stock_alert
            AND track_stock is enabled. Useful for reorder reports.

        is_active:
          - true  → active items only (typical default).
          - false → deleted/inactive items only.
          - Omit  → all items regardless of status.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Search by name:
          Input:  { "search": "USB" }
          Output: { "success": true, "total": 2, "items": [{ "id": 8, "name": "USB-C Cable", ... }] }

        Low-stock report:
          Input:  { "low_stock_only": true }
          Output: { "success": true, "total": 1, "items": [{ "id": 8, "name": "USB-C Cable",
                    "stock_quantity": 5, "low_stock_alert": 10 }] }

        No results:
          Input:  { "search": "Invisible Item" }
          Output: { "success": true, "total": 0, "items": [] }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $query = $company->inventoryItems();

        if (! empty($request['search'])) {
            $search = $request['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if (isset($request['is_active'])) {
            $query->where('is_active', (bool) $request['is_active']);
        }

        if (! empty($request['low_stock_only'])) {
            $query->where('track_stock', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_alert');
        }

        if (! empty($request['category'])) {
            $query->where('category', $request['category']);
        }

        $perPage = min((int) ($request['per_page'] ?? 20), 50);
        $page    = max((int) ($request['page']     ?? 1), 1);

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get([
                'id', 'name', 'sku', 'category', 'unit',
                'hsn_code', 'gst_rate', 'rate', 'cost_price', 'mrp',
                'track_stock', 'stock_quantity', 'low_stock_alert', 'is_active',
            ])
            ->toArray();

        return json_encode([
            'success' => true,
            'total'   => $total,
            'page'    => $page,
            'items'   => $items,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search'         => $schema->string(),
            'category'       => $schema->string(),
            'is_active'      => $schema->boolean(),
            'low_stock_only' => $schema->boolean(),
            'page'           => $schema->integer()->min(1),
            'per_page'       => $schema->integer()->min(1)->max(50),
        ];
    }
}
