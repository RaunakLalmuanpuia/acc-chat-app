<?php

namespace App\Ai\Tools\Client;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetClients extends BaseTool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    protected function purpose(): string
    {
        return 'List or search clients for the company, returning id, name, email, phone, city, and GST type for each match.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this to find a client_id before creating or updating an invoice.
        Call this before CreateClient to confirm the client does not already exist.
        Call this when the user asks to "list clients" or "show my customers".

        Do NOT call this after you already have the client_id in the current conversation —
        reuse the id you have. Do NOT call this twice with the same query in one turn.
        For a full profile (balance, recent invoices), use GetClientDetails instead.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        search (optional):
          - Searches across name, email, phone, and city.
          - Partial matches are supported: "Acme" matches "Acme Corp".
          - Omit to list all clients (up to per_page, default 20).

        is_active (optional):
          - true  → active clients only (default behaviour when omitted).
          - false → deleted/inactive clients only.
          - Omit  → all clients regardless of status.

        page / per_page:
          - 1-based page number and page size (max 50).
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Search by name:
          Input:  { "search": "Infosys" }
          Output: { "data": [{ "id": 14, "name": "Infosys Ltd", "email": "ar@infosys.com" }], "total": 1 }

        List all clients (no filter):
          Input:  {}
          Output: { "data": [...], "total": 38 }

        Client not found:
          Input:  { "search": "XYZ Unknown Corp" }
          Output: { "data": [], "total": 0 }  ← proceed to CreateClient
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        return json_encode($this->service->list(
            company:  $company,
            search:   $request['search']    ?? null,
            isActive: isset($request['is_active']) ? (bool) $request['is_active'] : null,
            page:     max((int) ($request['page']     ?? 1), 1),
            perPage:  min((int) ($request['per_page'] ?? 20), 50),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search'    => $schema->string(),
            'is_active' => $schema->boolean(),
            'page'      => $schema->integer()->min(1),
            'per_page'  => $schema->integer()->min(1)->max(15),
        ];
    }
}
