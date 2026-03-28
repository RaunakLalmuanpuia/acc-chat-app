<?php

namespace App\Ai\Tools\BankTransaction;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateTransactionReviewStatus extends BaseTool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    protected function purpose(): string
    {
        return 'Set the review_status of a bank transaction to pending, reviewed, or flagged.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user explicitly wants to change a transaction's review status —
        e.g. "mark this as reviewed", "flag that transaction", or "reset to pending".

        Use "flagged" for suspicious or unresolvable transactions that need human attention.
        Always include a note when flagging so the reason is recorded.

        Do NOT call this to categorise a transaction — use NarrateTransaction (which sets
        review_status to "reviewed" automatically as a side effect).
        Do NOT call this just to add a note without changing the status — pass the note
        alongside a status change.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        transaction_id (required):
          - Integer ID from GetBankTransactions results.

        review_status (required):
          - "pending"  → reset for re-review.
          - "reviewed" → mark as checked and categorised.
          - "flagged"  → needs human attention; always include a note explaining why.

        note (optional but strongly recommended when flagging):
          - Stored on the transaction to explain the status change.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Mark as reviewed:
          Input:  { "transaction_id": 301, "review_status": "reviewed" }
          Output: { "success": true, "review_status": "reviewed" }

        Flag a suspicious transaction:
          Input:  { "transaction_id": 302, "review_status": "flagged",
                    "note": "Amount does not match any open invoice — needs accountant review." }
          Output: { "success": true, "review_status": "flagged" }

        Reset to pending:
          Input:  { "transaction_id": 301, "review_status": "pending" }
          Output: { "success": true, "review_status": "pending" }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->service->updateReviewStatus(
            transactionId: (int) $request['transaction_id'],
            reviewStatus:  $request['review_status'],
            note:          $request['note'] ?? null,
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required()->description('ID of the bank transaction to update'),
            'review_status'  => $schema->string()->enum(['pending', 'reviewed', 'flagged'])->required()->description('The new review status to set'),
            'note'           => $schema->string()->description('Especially important when flagging — explain why the transaction is being flagged'),
        ];
    }
}
