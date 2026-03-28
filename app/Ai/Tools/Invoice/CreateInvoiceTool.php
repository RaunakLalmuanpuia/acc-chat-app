<?php

namespace App\Ai\Tools\Invoice;

use App\Ai\Tools\BaseTool;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateInvoiceTool extends BaseTool
{
    public function __construct(private readonly int $companyId) {}

    protected function purpose(): string
    {
        return 'Create a new DRAFT invoice for a client, returning the invoice_id needed for all subsequent invoice operations.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this after LookupClient has returned the client_id, and only when no
        suitable existing draft is already open for this client (check with
        GetActiveDraftsTool first if unsure).

        By default (force_new=false) the tool resumes an existing draft for the same
        client if one exists — this prevents accidental duplicates.
        Set force_new=true only when the user has explicitly said they want a separate
        new invoice (e.g. "create another invoice for Infosys").

        Do NOT call this before LookupClient — you must have client_id first.
        Do NOT call this to add line items — use AddLineItemTool after creation.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        client_id (required):
          - Integer from LookupClient. Never pass a name string here.

        invoice_date (required):
          - YYYY-MM-DD format. Use today's date if the user does not specify.

        due_date (optional):
          - YYYY-MM-DD. Omit to inherit the client's payment_terms offset from
            invoice_date, or if not applicable.

        invoice_type:
          - tax_invoice (default), proforma, credit_note, debit_note.

        currency:
          - ISO code, defaults to INR. Pass explicitly only if user specifies
            a different currency (e.g. USD for an export invoice).

        force_new:
          - Default false. Set true only after the user explicitly asked for a
            new/separate invoice when a draft already exists for this client.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Create a standard tax invoice (no existing draft):
          Input:  { "client_id": 14, "invoice_date": "2026-03-25" }
          Output: { "success": true, "invoice_id": 101, "invoice_number": "INV-20260325-101",
                    "status": "draft", "_resumed": false,
                    "message": "Draft invoice INV-20260325-101 created. invoice_id=101. Now add line items using AddLineItem." }

        Resume existing draft (force_new omitted or false):
          Input:  { "client_id": 14, "invoice_date": "2026-03-25" }
          Output: { "success": true, "invoice_id": 98, "invoice_number": "INV-20260320-98",
                    "status": "draft", "_resumed": true,
                    "message": "Resumed existing draft INV-20260320-98 (invoice_id=98)." }

        Force a second new invoice despite open draft:
          Input:  { "client_id": 14, "invoice_date": "2026-03-25", "force_new": true }
          Output: { "success": true, "invoice_id": 102, "invoice_number": "INV-20260325-102",
                    "_resumed": false, ... }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);

            $invoice = $service->createDraftInvoice(
                clientId:           $request['client_id'],
                invoiceDate:        $request['invoice_date'],
                dueDate:            $request['due_date']             ?? null,
                paymentTerms:       $request['payment_terms']        ?? null,
                notes:              $request['notes']                ?? null,
                termsAndConditions: $request['terms_and_conditions'] ?? null,
                invoiceType:        $request['invoice_type']         ?? 'tax_invoice',
                currency:           $request['currency']             ?? 'INR',
                forceNew:           (bool) ($request['force_new']    ?? false),
            );

            $resumed = $invoice['_resumed'] ?? false;

            return json_encode([
                'success'        => true,
                'invoice_id'     => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'supply_type'    => $invoice['supply_type'],
                'status'         => $invoice['status'],
                'company_name'   => $invoice['company_name'] ?? null,
                '_resumed'       => $resumed,
                'message'        => $resumed
                    ? "Resumed existing draft {$invoice['invoice_number']} (invoice_id={$invoice['id']})."
                    : "Draft invoice {$invoice['invoice_number']} created. invoice_id={$invoice['id']}. Now add line items using AddLineItem.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()
                ->description('Numeric ID of the client returned by LookupClient.')
                ->required(),

            'invoice_date' => $schema->string()
                ->description('Invoice date in YYYY-MM-DD format. Defaults to today if not specified.')
                ->required(),

            'due_date' => $schema->string()
                ->description('Payment due date in YYYY-MM-DD format. Optional.'),

            'payment_terms' => $schema->string()
                ->description('Payment terms text, e.g. "Net 30" or "Due on receipt". Inherits from client if omitted.'),

            'invoice_type' => $schema->string()
                ->description('One of: tax_invoice, proforma, credit_note, debit_note. Defaults to tax_invoice.')
                ->enum(['tax_invoice', 'proforma', 'credit_note', 'debit_note']),

            'currency' => $schema->string()
                ->description('ISO currency code. Defaults to INR.'),

            'notes' => $schema->string()
                ->description('Optional notes visible on the invoice.'),

            'terms_and_conditions' => $schema->string()
                ->description('Optional terms and conditions text.'),

            'force_new' => $schema->boolean()
                ->description(
                    'Set true when the user explicitly asked for a new or separate invoice ' .
                    'and a draft for this client already exists. Default false = resume existing draft.'
                ),
        ];
    }
}
