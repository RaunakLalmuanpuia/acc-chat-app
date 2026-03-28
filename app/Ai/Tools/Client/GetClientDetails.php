<?php

namespace App\Ai\Tools\Client;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetClientDetails extends BaseTool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    protected function purpose(): string
    {
        return 'Fetch full details for a single client: contact info, GST/PAN, payment terms, outstanding balance, and their 5 most recent invoices.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user asks for detailed information about a specific client —
        their address, GST number, outstanding balance, or recent invoice history.

        Do NOT call this just to get a client_id for invoice creation — GetClients or
        LookupClient returns the id with less overhead.
        Do NOT call this for a list of all clients — use GetClients instead.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        client_id (preferred):
          - Integer DB primary key. Fastest and unambiguous.

        name (fallback):
          - A name fragment. If it matches more than one client, the tool returns
            an error asking you to be more specific or use client_id.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Fetch by ID:
          Input:  { "client_id": 14 }
          Output: { "id": 14, "name": "Infosys Ltd", "email": "ar@infosys.com",
                    "gst_number": "29AABCI1234A1ZX", "outstanding_balance": 45000.00,
                    "recent_invoices": [...] }

        Fetch by name fragment:
          Input:  { "name": "Infosys" }
          Output: (same as above if unique match)

        Ambiguous name:
          Input:  { "name": "Tech" }
          Output: { "success": false, "message": "Multiple clients match 'Tech'. Use client_id." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $result = $this->service->resolveClient(
            $company,
            $request['client_id'] ?? null,
            $request['name']      ?? null,
        );

        if (is_array($result)) {
            return json_encode($result);
        }

        return json_encode($this->service->detail($result));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer(),
            'name'      => $schema->string(),
        ];
    }
}
