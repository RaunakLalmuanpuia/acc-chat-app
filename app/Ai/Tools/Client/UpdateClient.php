<?php

namespace App\Ai\Tools\Client;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateClient extends BaseTool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    protected function purpose(): string
    {
        return 'Update one or more fields on an existing client — only the fields you pass are changed.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to change a client's contact details, GST info,
        payment terms, credit limit, or active status.

        Do NOT call this to create a new client — use CreateClient.
        Do NOT call this to delete a client — use DeleteClient.
        Do NOT pass fields that should not change — only include what needs updating.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        client_id (required):
          - Integer DB primary key. Use GetClients or LookupClient to find it first.

        All other fields are optional partial-update fields:
          - payment_terms: integer number of days (30 = Net 30), not a string.
          - gst_type: one of regular, composition, unregistered, sez, overseas.
          - is_active: set false to deactivate (soft-hide) without deleting.
          - state_code: two-digit GST state code, e.g. "27" for Maharashtra.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Update email and phone:
          Input:  { "client_id": 14, "email": "new@infosys.com", "phone": "9123456789" }
          Output: { "success": true, "message": "Client updated." }

        Extend payment terms:
          Input:  { "client_id": 14, "payment_terms": 45 }
          Output: { "success": true, "message": "Client updated." }

        Deactivate a client:
          Input:  { "client_id": 14, "is_active": false }
          Output: { "success": true, "message": "Client updated." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $client = $company->clients()->find($request['client_id']);

        if (! $client) {
            return json_encode(['success' => false, 'message' => 'Client not found.']);
        }

        return json_encode($this->service->update($client, $request->toArray()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id'     => $schema->integer()->required(),
            'name'          => $schema->string(),
            'email'         => $schema->string(),
            'phone'         => $schema->string(),
            'gst_number'    => $schema->string(),
            'pan_number'    => $schema->string(),
            'gst_type'      => $schema->string()->enum(['regular', 'composition', 'unregistered', 'sez', 'overseas']),
            'address'       => $schema->string(),
            'city'          => $schema->string(),
            'state'         => $schema->string(),
            'state_code'    => $schema->string(),
            'pincode'       => $schema->string(),
            'country'       => $schema->string(),
            'currency'      => $schema->string(),
            'payment_terms' => $schema->integer(),
            'credit_limit'  => $schema->number(),
            'notes'         => $schema->string(),
            'is_active'     => $schema->boolean(),
        ];
    }
}
