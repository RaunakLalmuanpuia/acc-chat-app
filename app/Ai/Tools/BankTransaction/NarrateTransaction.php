<?php

namespace App\Ai\Tools\BankTransaction;

use App\Ai\Tools\BaseTool;
use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class NarrateTransaction extends BaseTool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    protected function purpose(): string
    {
        return 'Assign an accounting narration (category) to a bank transaction, setting its head and optional sub-head, and automatically marking it as reviewed.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to categorise or narrate a transaction —
        e.g. "mark this as rent expense" or "categorise the ₹5000 debit as salaries".

        Always derive a concise, meaningful note from the raw_narration and any party
        context available — do not leave note blank.

        Do NOT call this to change only the review status — use UpdateTransactionReviewStatus.
        Do NOT call this to link a transaction to an invoice — use ReconcileTransaction.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        transaction_id (required):
          - Integer ID from GetBankTransactions results.

        narration_head_id (required):
          - Integer ID of the accounting head (e.g. "Rent", "Salaries", "Sales").
          - Always required, even when a sub-head is also provided.

        narration_sub_head_id (optional):
          - Integer ID of the sub-head for finer categorisation.
          - Omit to categorise at head level only.

        note:
          - A concise human-readable description of what the transaction represents.
          - Always provide one — derive it from the raw narration, amount, and party name.

        source:
          - One of: manual, ai_suggested, rule_based, auto_matched.
          - Defaults to "manual". Use "ai_suggested" when you are proposing a category
            without explicit user instruction.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Categorise at head level:
          Input:  { "transaction_id": 301, "narration_head_id": 12,
                    "note": "Monthly office rent — March 2026", "source": "manual" }
          Output: { "success": true, "review_status": "reviewed" }

        Categorise with sub-head and party:
          Input:  { "transaction_id": 302, "narration_head_id": 8,
                    "narration_sub_head_id": 44, "party_name": "Infosys Ltd",
                    "note": "Payment received against INV-20260310-98",
                    "source": "ai_suggested" }
          Output: { "success": true, "review_status": "reviewed" }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->service->narrateTransaction(
            transactionId: (int) $request['transaction_id'],
            headId:        (int) $request['narration_head_id'],
            subHeadId:     isset($request['narration_sub_head_id']) ? (int) $request['narration_sub_head_id'] : null,
            source:        $request['source'] ?? 'manual',
            note:          $request['note'] ?? null,
            partyName:     $request['party_name'] ?? null,
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id'        => $schema->integer()->required()->description('ID of the bank transaction to narrate'),
            'narration_head_id'     => $schema->integer()->required()->description('ID of the narration head — always required'),
            'narration_sub_head_id' => $schema->integer()->description('ID of the narration sub-head — optional'),
            'note'                  => $schema->string()->description('Concise description of what the transaction represents — always provide one'),
            'party_name'            => $schema->string()->description('Optional vendor, client, or party name associated with this transaction'),
            'source'                => $schema->string()->enum(['manual', 'ai_suggested', 'rule_based', 'auto_matched'])->description('Narration source — defaults to manual'),
        ];
    }
}
