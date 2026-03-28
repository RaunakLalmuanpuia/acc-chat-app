<?php

namespace App\Ai\Tools\Inventory;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteInventoryItem extends BaseTool
{
    public function __construct(protected User $user) {}

    protected function purpose(): string
    {
        return 'Soft-delete an inventory item, removing it from the active catalogue while preserving its data on any existing invoice line items.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this only when the user explicitly asks to delete or remove a product/service
        from the inventory catalogue.

        Do NOT call this to deactivate an item temporarily — there is no separate
        deactivate tool; inform the user that deletion is soft and reversible by an admin.
        Do NOT call this if the user only wants to update the item's price or details.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        item_id (preferred):
          - Integer DB primary key from GetInventory or LookupInventoryItemTool.
          - Always prefer this to avoid accidental deletion of a similarly-named item.

        name (fallback):
          - Partial name match. If multiple items match, the tool deletes the first one —
            confirm with the user before proceeding when name is ambiguous.

        Exactly one of item_id or name must be provided.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Delete by ID:
          Input:  { "item_id": 8 }
          Output: { "success": true, "message": "Inventory item \"USB-C Cable\" has been deleted successfully." }

        Delete by name:
          Input:  { "name": "USB-C Cable" }
          Output: { "success": true, "message": "Inventory item \"USB-C Cable\" has been deleted successfully." }

        Item not found:
          Input:  { "item_id": 9999 }
          Output: { "success": false, "message": "Inventory item not found." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $query = $company->inventoryItems();

        if (! empty($request['item_id'])) {
            $query->where('id', $request['item_id']);
        } elseif (! empty($request['name'])) {
            $query->where('name', 'like', '%' . $request['name'] . '%');
        } else {
            return json_encode(['success' => false, 'message' => 'Provide either item_id or name.']);
        }

        $item = $query->first();

        if (! $item) {
            return json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        }

        $name = $item->name;
        $item->delete();

        return json_encode([
            'success' => true,
            'message' => "Inventory item \"{$name}\" has been deleted successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer(),
            'name'    => $schema->string(),
        ];
    }
}
