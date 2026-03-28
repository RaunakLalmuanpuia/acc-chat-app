<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class LookupClientTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Search for clients by name, email, or numeric ID, returning up to 10 matches with their address, GST, state, and payment terms.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this at the start of any invoice workflow to resolve the client_id from
        a name or email the user mentioned.

        Do NOT call this if you already have the client_id in the current conversation —
        reuse it. Do NOT call this twice with the same query in one turn (loop guard).
        Do NOT use this to create a client — use CreateClient if the search returns empty.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        query (required):
          - Client name fragment, email address, or numeric client ID as a string.
          - Partial matches are supported: "Info" matches "Infosys Ltd".
          - Pass the exact string the user mentioned; do not modify or truncate it.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Search by name:
          Input:  { "query": "Infosys" }
          Output: { "clients": [{ "id": 14, "name": "Infosys Ltd",
                    "email": "ar@infosys.com", "state": "Karnataka", "state_code": "29",
                    "gst_number": "29AABCI1234A1ZX", "payment_terms": 30 }], "count": 1 }

        Not found:
          Input:  { "query": "XYZ Unknown Corp" }
          Output: { "error": "No clients found matching 'XYZ Unknown Corp'. Try a shorter name or check the spelling." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $service = new InvoiceAgentService($this->companyId);
        $clients = $service->findClients($request['query']);

        if (empty($clients)) {
            return json_encode(['error' => "No clients found matching '{$request['query']}'. Try a shorter name or check the spelling."]);
        }

        return json_encode(['clients' => $clients, 'count' => count($clients)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Client name fragment, email, or numeric client ID to search for.')
                ->required(),
        ];
    }
}


// ─────────────────────────────────────────────────────────────────────────────
