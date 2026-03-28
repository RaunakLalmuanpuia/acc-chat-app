<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetActiveDraftsTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Return open draft invoices for this company, optionally filtered by invoice number or client name — use this to recover an invoice_id when context has been lost.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this at the START of any turn where you do not already have an invoice_id
        in context, before calling AddLineItemTool or any other tool that requires one.

        Call with invoice_number to resolve a specific draft to its invoice_id when the
        user references an invoice by number (e.g. "continue INV-20260310-98").

        Call with client_name to check whether an open draft already exists before
        calling CreateInvoiceTool.

        Call with no parameters to list all open drafts.

        Do NOT call this when you already have the invoice_id from earlier in the
        conversation — reuse it.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        invoice_number (optional):
          - Exact invoice number, e.g. "INV-20260310-17453".
          - When provided and found, the response includes invoice_id prominently.

        client_name (optional):
          - Partial client name to filter results. Useful before CreateInvoiceTool
            to check for an existing draft for this client.

        Both are optional. Omit both to return all open drafts.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Resolve invoice_id from number:
          Input:  { "invoice_number": "INV-20260310-17453" }
          Output: { "drafts": [{ "id": 98, "invoice_number": "INV-20260310-17453", ... }],
                    "invoice_id": 98,
                    "message": "Found draft INV-20260310-17453 — invoice_id=98. Use this id to add line items." }

        Check for existing draft before creating:
          Input:  { "client_name": "Infosys" }
          Output: { "drafts": [{ "id": 98, ... }], "count": 1,
                    "message": "Found open drafts. Use the invoice_id from the relevant draft to continue." }

        No drafts:
          Input:  {}
          Output: { "drafts": [], "message": "No open drafts found. Safe to create a new invoice." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);

            $invoiceNumber = isset($request['invoice_number']) && trim($request['invoice_number']) !== ''
                ? trim($request['invoice_number'])
                : null;

            $clientName = isset($request['client_name']) && trim($request['client_name']) !== ''
                ? trim($request['client_name'])
                : null;

            $drafts = $service->getActiveDrafts(
                invoiceNumber: $invoiceNumber,
                clientName:    $clientName,
            );

            if (empty($drafts)) {
                $context = $invoiceNumber
                    ? "No draft found with invoice number {$invoiceNumber}."
                    : 'No open drafts found. Safe to create a new invoice.';

                return json_encode(['drafts' => [], 'message' => $context]);
            }

            if ($invoiceNumber && count($drafts) === 1) {
                return json_encode([
                    'drafts'     => $drafts,
                    'invoice_id' => $drafts[0]['id'],
                    'message'    => "Found draft {$invoiceNumber} — invoice_id={$drafts[0]['id']}. Use this id to add line items.",
                ]);
            }

            return json_encode([
                'drafts'  => $drafts,
                'count'   => count($drafts),
                'message' => 'Found open drafts. Use the invoice_id from the relevant draft to continue.',
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_number' => $schema->string()
                ->description('Exact invoice number to look up, e.g. "INV-20260310-17453".'),

            'client_name' => $schema->string()
                ->description('Filter drafts by client name fragment.'),
        ];
    }
}
