<?php

namespace App\Ai\Tools\Client;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateClient extends BaseTool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    protected function purpose(): string
    {
        return 'Create a new client/customer record for the company.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to add a new client that does not yet exist in the system.

        Do NOT call this without first calling GetClients (or LookupClient) to verify the client
        does not already exist — duplicate clients cause billing and reporting problems.
        Do NOT call this to update an existing client — use UpdateClient instead.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        name (required):
          - Full legal or trading name of the client.

        state / state_code:
          - Indian state name and its two-letter GST state code (e.g. "Maharashtra" / "27").
          - Required for correct GST supply-type determination (intra vs inter-state).

        gst_type:
          - One of: regular, composition, unregistered, sez, overseas.
          - Drives how GST is calculated on invoices for this client.
          - Default to "unregistered" if the user does not specify.

        payment_terms:
          - Integer number of days (e.g. 30 for Net 30). Not a string like "Net 30".

        credit_limit:
          - Decimal number in the company's default currency. Omit if not specified.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Create a GST-registered client:
          Input:  { "name": "Infosys Ltd", "state": "Karnataka", "state_code": "29",
                    "gst_number": "29AABCI1234A1ZX", "gst_type": "regular",
                    "email": "ar@infosys.com", "phone": "9876543210", "payment_terms": 30 }
          Output: { "success": true, "id": 42, "name": "Infosys Ltd" }

        Create an unregistered / consumer client:
          Input:  { "name": "Rahul Sharma", "state": "Delhi", "state_code": "07",
                    "gst_type": "unregistered", "phone": "9999999999" }
          Output: { "success": true, "id": 43, "name": "Rahul Sharma" }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found. Please set up your company first.']);
        }

        return json_encode($this->service->create($company, $request->toArray()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name'          => $schema->string()->required(),
            'state'         => $schema->string()->required(),
            'state_code'    => $schema->string(),
            'gst_number'    => $schema->string(),
            'pan_number'    => $schema->string(),
            'gst_type'      => $schema->string()->enum(['regular', 'composition', 'unregistered', 'sez', 'overseas']),
            'email'         => $schema->string(),
            'phone'         => $schema->string(),
            'address'       => $schema->string(),
            'city'          => $schema->string(),
            'pincode'       => $schema->string(),
            'country'       => $schema->string(),
            'currency'      => $schema->string(),
            'payment_terms' => $schema->integer(),
            'credit_limit'  => $schema->number(),
            'notes'         => $schema->string(),
        ];
    }
}
