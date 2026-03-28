<?php

namespace App\Ai\Tools\BankTransaction;

use App\Ai\Tools\BaseTool;
use App\Ai\Tools\BankTransaction\Filters\TransactionFilters;
use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetBankTransactions extends BaseTool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    protected function purpose(): string
    {
        return 'Retrieve bank transactions for the user\'s company, with optional filters for date range, type, review status, and reconciliation state.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call with NO parameters when the user asks to "show transactions", "list bank entries",
        or any general request — do not add filters unless the user explicitly stated them
        in their current message.

        Do NOT carry filters forward from a previous turn. Each call should reflect only
        what the user said in their most recent message.
        Do NOT infer type="credit" or type="debit" from context — only set it when the
        user's message contains one of those exact words (or "payments in" / "payments out").
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        from_date / to_date:
          - YYYY-MM-DD format. Set only when the user explicitly mentions a date or period.
          - When a date range is set, type/review_status/is_reconciled are ignored —
            all transaction types are returned within the range.

        type:
          - "credit" or "debit". Set ONLY when the user's message contains one of:
            "credit", "debit", "payments in", "payments out".

        review_status:
          - "pending", "reviewed", or "flagged". Set only when the user says one of these words.

        is_reconciled:
          - Pass true only when the user explicitly asks for reconciled transactions.

        limit:
          - Default 20, max 50. Set only when the user specifies a count.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        General list (no filters):
          Input:  {}
          Output: { "transactions": [...], "total": 42 }

        Only credit transactions:
          Input:  { "type": "credit" }
          Output: { "transactions": [...], "total": 18 }

        Transactions in a date range:
          Input:  { "from_date": "2026-03-01", "to_date": "2026-03-31" }
          Output: { "transactions": [...], "total": 9 }

        Pending review:
          Input:  { "review_status": "pending" }
          Output: { "transactions": [...], "total": 5 }

        WRONG — do not carry type from a prior turn:
          User turn 1: "show me credit transactions"
          User turn 2: "now show flagged ones"
          Input for turn 2: { "type": "credit", "review_status": "flagged" }  ← WRONG
          Input for turn 2: { "review_status": "flagged" }                    ← CORRECT
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        Log::info('GetBankTransactions called', $request->toArray());

        $filters = TransactionFilters::fromLlmInput($request->toArray());

        Log::info('GetBankTransactions resolved', [
            'raw'      => $request->toArray(),
            'resolved' => (array) $filters,
        ]);

        return json_encode($this->service->getTransactions($filters));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_date' => $schema->string()->description(
                'Start date (YYYY-MM-DD). Only set when user explicitly mentions a start date or period.'
            ),
            'to_date' => $schema->string()->description(
                'End date (YYYY-MM-DD). Only set when user explicitly mentions an end date or period.'
            ),
            'type' => $schema->string()->enum(['credit', 'debit'])->description(
                'Only set when user explicitly says "credit", "debit", "payments in", or "payments out".'
            ),
            'review_status' => $schema->string()->enum(['pending', 'reviewed', 'flagged'])->description(
                'Only set when user explicitly says "pending", "reviewed", or "flagged".'
            ),
            'is_reconciled' => $schema->boolean()->description(
                'Pass true only when user explicitly asks for reconciled transactions.'
            ),
            'limit' => $schema->integer()->min(1)->max(50)->description(
                'Number of results (default 20). Only set if user specifies a count.'
            ),
        ];
    }
}
