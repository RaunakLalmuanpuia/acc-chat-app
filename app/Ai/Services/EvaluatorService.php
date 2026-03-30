<?php

namespace App\Ai\Services;

use Illuminate\Support\Facades\Log;

/**
 * EvaluatorService  (v3 — specific per-intent retry guidance)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v2
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v3 — specific per-intent retry guidance (Anthropic "articulated feedback"):
 *
 *   buildRetryAugmentation() previously emitted a generic "previous response
 *   was incomplete" header. The Anthropic "Building Effective Agents" guide
 *   requires the evaluator to provide articulated feedback so the retry agent
 *   knows exactly what it missed. Added RETRY_GUIDANCE const with per-intent
 *   failure description and fix instruction. The retry block now names the
 *   missing completion marker and the exact tool that returns it.
 *
 * v2 — FIX 16 — post-retry re-evaluate call was misleading:
 *
 *   v1 re-called evaluate(isRetry: true) after the retry pass purely to log
 *   the final outcome distribution. The method signature implies it might
 *   trigger evaluation logic, but isRetry=true just suppressed the retry
 *   and returned EvaluationResult::pass() unconditionally. This confused
 *   readers into thinking the evaluator was doing useful work on the second
 *   call, and made ChatOrchestrator harder to reason about.
 *
 *   Fix: extract the logging concern into a dedicated logFinalOutcome() method.
 *   evaluate() is now only called for genuine evaluation with potential retry.
 *   isRetry parameter is kept for safety but is no longer used by the orchestrator
 *   — it remains available if external callers need the guard.
 *
 *   ChatOrchestrator v8 now calls:
 *     $this->evaluator->logFinalOutcome($responses, $intents, $message);
 *   instead of:
 *     $this->evaluator->evaluate(..., isRetry: true);
 *
 * All v1 features preserved:
 *   - COMPLETION_PATTERNS contractual marker check (highest priority)
 *   - Outcome signal fallback ('completed'/'partial' → done)
 *   - buildRetryAugmentation() with completed-agent context injection
 *   - EvaluationResult value object (pass / retry)
 */
class EvaluatorService
{
    /**
     * Completion markers that definitively prove an agent completed its task.
     * These are contractual signals baked into each agent's instructions.
     *
     * @see ClientAgent::domainInstructions()     [CLIENT_ID:{id}]
     * @see InventoryAgent::domainInstructions()  [INVENTORY_ITEM_ID:{id}]
     * @see NarrationAgent::domainInstructions()  [NARRATION_HEAD_ID:{id}]
     * @see InvoiceAgent::domainInstructions()    INV-YYYYMMDD-{n}
     */
    private const COMPLETION_PATTERNS = [
        'client'           => '/\[CLIENT_ID:\d+\]/',
        'inventory'        => '/\[INVENTORY_ITEM_ID:\d+\]/',
        'narration'        => '/\[NARRATION_HEAD_ID:\d+\]/',
        'invoice'          => '/INV-\d{8}-\d+/',
        'bank_transaction' => null, // outcome signal only — no embedded marker
        'business'         => null, // outcome signal only
    ];

    /**
     * Per-intent specific failure description and fix instruction.
     * Injected into the retry augmentation so the retrying agent knows
     * exactly what it missed, not just that it needs to try again.
     *
     * Anthropic "Building Effective Agents": evaluator-optimizer requires
     * "articulated feedback" — the retry agent must know what to fix.
     */
    private const RETRY_GUIDANCE = [
        'client' => [
            'failure' => 'ClientAgent did not emit a [CLIENT_ID:n] completion tag in its reply.',
            'fix'     => 'After creating or confirming the client, you MUST output [CLIENT_ID:{id}] on its own line — where {id} is the actual numeric database ID returned by the create_client or get_clients tool.',
        ],
        'inventory' => [
            'failure' => 'InventoryAgent did not emit an [INVENTORY_ITEM_ID:n] completion tag in its reply.',
            'fix'     => 'After creating or confirming the inventory item, you MUST output [INVENTORY_ITEM_ID:{id}] on its own line — where {id} is the actual numeric database ID returned by the create_inventory_item or get_inventory tool.',
        ],
        'narration' => [
            'failure' => 'NarrationAgent did not emit a [NARRATION_HEAD_ID:n] completion tag in its reply.',
            'fix'     => 'After creating or confirming the narration head, you MUST output [NARRATION_HEAD_ID:{id}] on its own line — where {id} is the actual numeric database ID returned by the create_narration_head tool.',
        ],
        'invoice' => [
            'failure' => 'InvoiceAgent did not emit an invoice number matching the pattern INV-YYYYMMDD-N.',
            'fix'     => 'You MUST create the invoice (or retrieve the existing draft) and include its invoice number in your reply. The invoice number is returned by create_invoice and get_active_drafts.',
        ],
        'bank_transaction' => [
            'failure' => 'BankTransactionAgent did not signal task completion — no write tool was called or the response ended with a question.',
            'fix'     => 'Complete the transaction action the user requested (narrate, reconcile, or update review status). Do NOT ask clarifying questions — use the information already in the conversation.',
        ],
        'business' => [
            'failure' => 'BusinessProfileAgent did not signal task completion — no write tool was called.',
            'fix'     => 'Complete the profile creation or update the user requested. If all required fields are present in the conversation, call the tool immediately.',
        ],
    ];

    // ── Primary API ────────────────────────────────────────────────────────────

    /**
     * Evaluate the results from a dispatch pass.
     *
     * Returns an EvaluationResult indicating whether any intents need to be
     * retried, and if so which ones and what augmented context to add.
     *
     * @param  array<string, array> $responses  Results from dispatchAll(), keyed by intent
     * @param  string[]             $intents    Original intent list for this turn
     * @param  string               $message    Original user message
     * @param  bool                 $isRetry    Guard: when true, never adds to needsRetry
     *                                          (prevents a third loop). Kept for back-compat;
     *                                          ChatOrchestrator now calls logFinalOutcome()
     *                                          instead of evaluate(isRetry: true).
     */
    public function evaluate(
        array  $responses,
        array  $intents,
        string $message,
        bool   $isRetry = false,
    ): EvaluationResult {
        $needsRetry         = [];
        $completedByIntent  = [];

        foreach ($intents as $intent) {
            $result = $responses[$intent] ?? null;

            if ($result === null || ($result['_error'] ?? false)) {
                $completedByIntent[$intent] = false;
                continue;
            }

            $rawReply      = $result['_raw_reply'] ?? $result['reply'] ?? '';
            $outcomeSignal = $result['_outcome']   ?? null;

            $completed = $this->isCompleted($intent, $rawReply, $outcomeSignal);
            $completedByIntent[$intent] = $completed;

            if (!$completed && !$isRetry) {
                $needsRetry[] = $intent;
            }
        }

        Log::info('[EvaluatorService] Evaluation complete', [
            'intents'             => $intents,
            'completed_by_intent' => $completedByIntent,
            'needs_retry'         => $needsRetry,
            'is_retry'            => $isRetry,
        ]);

        if (empty($needsRetry)) {
            return EvaluationResult::pass();
        }

        $augmentation = $this->buildRetryAugmentation($needsRetry, $responses);

        Log::warning('[EvaluatorService] Retry triggered', [
            'intents_to_retry' => $needsRetry,
            'augmentation_len' => strlen($augmentation),
        ]);

        return EvaluationResult::retry($needsRetry, $augmentation);
    }

    /**
     * FIX 16 — Log the final completion distribution after the retry pass.
     *
     * Previously the orchestrator called evaluate(isRetry: true) for this,
     * which was misleading: it implied evaluation might trigger a third loop,
     * forced callers to remember the isRetry flag, and buried the logging
     * concern inside the evaluation method.
     *
     * This method is purely observational — it inspects $responses, computes
     * per-intent completion status, and logs the result. No EvaluationResult
     * is returned because no retry decision is needed.
     *
     * Call this once at the end of the retry pass, after merging retry results
     * back into $responses, to record the final turn outcome in the logs.
     *
     * @param  array<string, array> $responses  Final merged responses (Pass 1 + retry)
     * @param  string[]             $intents    Full original intent list
     * @param  string               $message    Original user message (for log context)
     */
    public function logFinalOutcome(
        array  $responses,
        array  $intents,
        string $message,
    ): void {
        $completedByIntent = [];

        foreach ($intents as $intent) {
            $result = $responses[$intent] ?? null;

            if ($result === null || ($result['_error'] ?? false)) {
                $completedByIntent[$intent] = false;
                continue;
            }

            $rawReply      = $result['_raw_reply'] ?? $result['reply'] ?? '';
            $outcomeSignal = $result['_outcome']   ?? null;

            $completedByIntent[$intent] = $this->isCompleted($intent, $rawReply, $outcomeSignal);
        }

        $completedCount = count(array_filter($completedByIntent));
        $totalCount     = count($completedByIntent);
        $ratePct        = $totalCount > 0 ? round($completedCount / $totalCount * 100, 1) : 0.0;

        Log::info('[EvaluatorService] Final outcome after retry pass', [
            'intents'             => $intents,
            'completed_by_intent' => $completedByIntent,
            'completion_rate_pct' => $ratePct,
            'message_preview'     => mb_substr($message, 0, 80),
        ]);

        if ($ratePct < 100.0) {
            Log::warning('[EvaluatorService] Some intents still incomplete after retry', [
                'incomplete' => array_keys(array_filter($completedByIntent, fn($v) => !$v)),
                'action'     => 'User will see clarifying question; natural next-message correction.',
            ]);
        }
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Determine if a single agent completed its task.
     *
     * Priority order:
     *   1. Hard completion markers in the reply (contractual — strongest signal)
     *   2. Outcome signal = 'completed'
     *   3. Outcome signal = 'partial' (write tool called + clarifying question)
     *      → treat as completed since a write DID happen
     *   4. Everything else → not completed
     */
    private function isCompleted(string $intent, string $rawReply, ?string $outcomeSignal): bool
    {
        $pattern = self::COMPLETION_PATTERNS[$intent] ?? null;
        if ($pattern !== null && preg_match($pattern, $rawReply)) {
            return true;
        }

        return in_array($outcomeSignal, ['completed', 'partial'], true);
    }

    /**
     * Build an augmentation preamble to inject into the retry prompt.
     *
     * v3: Each failing intent gets specific failure reason + fix instruction
     * (Anthropic "Building Effective Agents" — evaluator must provide
     * "articulated feedback" so the retry agent knows exactly what to fix,
     * not just that it failed).
     *
     * Uses the same box-drawing format as AgentContextBlackboard::buildContextPreamble()
     * so InvoiceAgent's BLACKBOARD DEPENDENCY CHECK (which looks for the ╔══ header)
     * correctly identifies the block as confirmed prior context and does NOT fall
     * through to its "PENDING" path.
     *
     * @param  string[]             $intentsToRetry
     * @param  array<string, array> $responses
     */
    private function buildRetryAugmentation(array $intentsToRetry, array $responses): string
    {
        $lines = [
            '╔═══════════════════════════════════════════════════════════════════╗',
            '║  EVALUATOR FEEDBACK — this is your second attempt                ║',
            '╚═══════════════════════════════════════════════════════════════════╝',
            '',
        ];

        // ── Per-intent specific failure guidance ──────────────────────────────
        // Tell each retrying agent exactly what it missed and how to fix it.
        foreach ($intentsToRetry as $intent) {
            $guidance = self::RETRY_GUIDANCE[$intent] ?? null;

            if ($guidance !== null) {
                $lines[] = "── [WHY {$intent} FAILED] ────────────────────────────────────────────";
                $lines[] = "⚠  {$guidance['failure']}";
                $lines[] = "✦  Fix: {$guidance['fix']}";
                $lines[] = '';
            }
        }

        // ── PRIOR AGENT CONTEXT from completed agents ─────────────────────────
        // Include the standard ╔══ sentinel so InvoiceAgent's BLACKBOARD
        // DEPENDENCY CHECK classifies this block as confirmed, not pending.
        $hasCompletedContext = false;
        $contextLines        = [
            '╔══════════════════════════════════════════════════════════════╗',
            '║  PRIOR AGENT CONTEXT — treat as established fact             ║',
            '║  Do NOT re-fetch, re-create, or contradict this information. ║',
            '║  CRITICAL: This context was ALREADY shown to the user.       ║',
            '║  Use it silently for lookups and decisions only.             ║',
            '╚══════════════════════════════════════════════════════════════╝',
            '',
        ];

        foreach ($responses as $intent => $result) {
            if (in_array($intent, $intentsToRetry, true)) continue;
            if ($result['_error'] ?? false) continue;

            // Use raw reply (with [INVENTORY_ITEM_ID:N] etc. tags intact) so the
            // preamble InvoiceAgent receives during a retry pass still contains
            // the structured tags it needs to parse item→ID mappings.
            $reply = $result['_raw_reply'] ?? $result['reply'] ?? '';
            if (empty(trim($reply)) || trim($reply) === 'HANDOFF') continue;

            $hasCompletedContext  = true;
            $contextLines[]       = "── [{$intent} agent completed] ──────────────────────────────────";
            $contextLines[]       = $reply;
            $contextLines[]       = "── [end {$intent} context] ──────────────────────────────────────";
            $contextLines[]       = '';
        }

        if ($hasCompletedContext) {
            foreach ($contextLines as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '════════════════════════════════════════════════════════════════════';
        $lines[] = '';
        $lines[] = 'INSTRUCTION: Do NOT ask clarifying questions. Use the failure guidance';
        $lines[] = 'above and the completed-agent context to finish your task now.';
        $lines[] = 'If a required ID is in the context, use it directly without calling';
        $lines[] = 'a lookup tool.';
        $lines[] = '';

        return implode("\n", $lines);
    }
}

/**
 * Value object returned by EvaluatorService::evaluate().
 */
final class EvaluationResult
{
    private function __construct(
        public readonly bool   $shouldRetry,
        /** @var string[] */
        public readonly array  $intentsToRetry,
        public readonly string $augmentation,
    ) {}

    public static function pass(): self
    {
        return new self(false, [], '');
    }

    /** @param string[] $intentsToRetry */
    public static function retry(array $intentsToRetry, string $augmentation): self
    {
        return new self(true, $intentsToRetry, $augmentation);
    }
}
