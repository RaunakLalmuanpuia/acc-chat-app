<?php

namespace App\Ai\Tools\Client;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteClient extends BaseTool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    protected function purpose(): string
    {
        return 'Soft-delete a client, preserving their full invoice history.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this only when the user explicitly asks to delete or remove a client.

        Do NOT call this to deactivate a client temporarily — use UpdateClient with
        is_active=false instead. Soft-delete is permanent from the client list but
        invoice history is always preserved.

        If the client has unpaid invoices, the tool will warn you and return success=false.
        Ask the user to confirm before retrying with force=true.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        client_id (preferred):
          - Integer DB primary key from GetClients or LookupClient.
          - Always prefer this over name to avoid accidental deletion of the wrong client.

        name (fallback):
          - Used only when client_id is not available. Must be unique enough to match
            exactly one client — if multiple clients match, the tool returns an error.

        force:
          - Default false. Set true only after the user has confirmed they want to delete
            a client with outstanding unpaid invoices.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Delete by ID (no outstanding invoices):
          Input:  { "client_id": 42 }
          Output: { "success": true, "message": "Client deleted." }

        Delete attempt blocked by unpaid invoices:
          Input:  { "client_id": 42 }
          Output: { "success": false, "message": "Client has 3 unpaid invoices. Pass force=true to confirm." }

        Force-delete after user confirmation:
          Input:  { "client_id": 42, "force": true }
          Output: { "success": true, "message": "Client deleted." }
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

        return json_encode($this->service->delete($result, (bool) ($request['force'] ?? false)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer(),
            'name'      => $schema->string(),
            'force'     => $schema->boolean(),
        ];
    }
}
