<?php

namespace App\Ai\Services;

use Illuminate\Support\Facades\Log;

/**
 * EvaluatorService  (v2 — Fix 16: logFinalOutcome() replaces evaluate(isRetry:true))
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v1
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 16 — post-retry re-evaluate call was misleading:
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
            '║  The previous response was incomplete (clarifying question only). ║',
            '║  Use the context below to complete the task without asking again. ║',
            '╚═══════════════════════════════════════════════════════════════════╝',
            '',
            // Also include the standard PRIOR AGENT CONTEXT header so agents
            // that parse the ╔══ sentinel can correctly classify this as
            // confirmed context rather than pending.
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

            $reply = $result['reply'] ?? '';
            if (empty(trim($reply)) || trim($reply) === 'HANDOFF') continue;

            $lines[] = "── [{$intent} agent completed] ──────────────────────────────────";
            $lines[] = $reply;
            $lines[] = "── [end {$intent} context] ──────────────────────────────────────";
            $lines[] = '';
        }

        $lines[] = '════════════════════════════════════════════════════════════════════';
        $lines[] = '';
        $lines[] = 'INSTRUCTION: Do NOT ask clarifying questions. Use the context above';
        $lines[] = 'to complete your task. If a required ID is in the context, use it';
        $lines[] = 'directly without calling a lookup tool.';
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
