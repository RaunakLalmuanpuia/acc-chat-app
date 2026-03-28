<?php

namespace App\Ai\Tools\BankTransaction;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReconcileTransaction extends BaseTool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    protected function purpose(): string
    {
        return 'Link a bank transaction to a confirmed invoice for reconciliation, setting is_reconciled=true and storing the invoice reference on the transaction.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this only after presenting the proposed transaction-to-invoice match to the
        user and receiving their explicit confirmation — never reconcile speculatively.

        Do NOT call this if the transaction is already reconciled — the tool will return
        an error. Check is_reconciled in the GetBankTransactions response first.
        Do NOT call this to categorise a transaction — use NarrateTransaction for that.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        transaction_id (required):
          - Integer ID from GetBankTransactions results.

        invoice_id (required):
          - Integer DB primary key of the invoice to link.
          - Obtain from SearchInvoicesTool or GetActiveDraftsTool.
          - The invoice should be in "sent" status (issued but awaiting payment).
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Reconcile after user confirmation:
          Input:  { "transaction_id": 301, "invoice_id": 98 }
          Output: { "success": true, "is_reconciled": true,
                    "message": "Transaction reconciled with invoice INV-20260310-98." }

        Already reconciled:
          Input:  { "transaction_id": 301, "invoice_id": 98 }
          Output: { "error": "Transaction is already reconciled." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->service->reconcileTransaction(
            transactionId: (int) $request['transaction_id'],
            invoiceId:     (int) $request['invoice_id'],
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required()->description('ID of the bank transaction to reconcile'),
            'invoice_id'     => $schema->integer()->required()->description('ID of the confirmed invoice to link to this transaction'),
        ];
    }
}
