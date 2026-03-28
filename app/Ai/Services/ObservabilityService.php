<?php

namespace App\Ai\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * ObservabilityService  (v5 — Fix 4: reset turnMetrics in setTurnId())
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v4
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 4 — $turnMetrics leaks across requests on Octane/Swoole:
 *
 *   ObservabilityService is registered as a singleton (typical in Laravel DI).
 *   v4 only reset $turnMetrics at the END of recordTurnSummary(). Two code
 *   paths skipped that reset entirely:
 *
 *     (a) ScopeGuard early-return in ChatOrchestrator::handle() — the guard
 *         returns before executeDispatch() is ever called, so recordTurnSummary()
 *         is never reached. Any $turnMetrics accumulated from a prior request
 *         in the same worker process are included in the next turn's summary.
 *
 *     (b) Unhandled exception after some recordAgentCall() calls but before
 *         the turn completes — same stale-state problem.
 *
 *   Fix: reset $turnMetrics (and $turnId) at the START of each new turn
 *   inside setTurnId(). ChatOrchestrator always calls setTurnId() as the
 *   very first operation in both handle() and confirm(), before any agent
 *   work begins, making this the correct reset point.
 *
 *   This guarantees each turn starts with a clean slate regardless of what
 *   happened in the previous turn on the same Octane worker.
 *
 * All v4 changes preserved:
 *   - $cachedTokens properly wired as a parameter (v4 bug fix)
 *   - 'completed'/'clarifying'/'partial'/'error' outcome signals
 *   - Per-turn summary with completion_rate_pct
 *   - Cached-token cost discount (50% rate)
 */
class ObservabilityService
{
    /**
     * Approximate cost per 1K tokens (USD). Update when OpenAI pricing changes.
     */
    private const MODEL_COSTS = [
        'gpt-4o'      => ['input' => 0.0025,  'cached' => 0.00125,  'output' => 0.010],
        'gpt-4o-mini' => ['input' => 0.00015, 'cached' => 0.000075, 'output' => 0.0006],
    ];

    private const OUTCOME_SIGNALS = ['completed', 'clarifying', 'partial', 'error'];

    /** @var array<int, array> */
    private array $turnMetrics = [];

    private ?string $turnId = null;

    // ── Turn lifecycle ─────────────────────────────────────────────────────────

    /**
     * Mark the start of a new turn.
     *
     * FIX 4: Resets $turnMetrics and $turnId here — not just at the end of
     * recordTurnSummary() — so Octane workers always begin each request with
     * clean state, even if the previous turn ended via early-return, exception,
     * or any other path that skipped recordTurnSummary().
     *
     * ChatOrchestrator calls this as its very first operation in both handle()
     * and confirm(), guaranteeing the reset fires before any agent work begins.
     */
    public function setTurnId(string $turnId): void
    {
        // FIX 4: reset accumulated state from any prior turn on this worker
        $this->turnMetrics = [];
        $this->turnId      = $turnId;
    }

    // ── Recording ─────────────────────────────────────────────────────────────

    /**
     * Record a single specialist agent call.
     *
     * @param  ?int    $cachedTokens  Tokens served from the provider's prompt cache
     *                               (billed at 50% of full input rate).
     * @param  ?string $outcomeSignal 'completed' | 'clarifying' | 'partial' | 'error' | null
     */
    public function recordAgentCall(
        string  $intent,
        string  $userId,
        ?string $conversationId,
        string  $model,
        int     $latencyMs,
        ?int    $inputTokens   = null,
        ?int    $outputTokens  = null,
        ?int    $cachedTokens  = null,
        bool    $success       = true,
        ?string $errorMessage  = null,
        ?string $outcomeSignal = null,
    ): void {
        $outcome = !$success
            ? 'error'
            : (in_array($outcomeSignal, self::OUTCOME_SIGNALS, true) ? $outcomeSignal : null);

        $estimatedCostUsd = $this->estimateCost($model, $inputTokens, $outputTokens, $cachedTokens);

        $metric = [
            'intent'             => $intent,
            'user_id'            => $userId,
            'conversation_id'    => $conversationId,
            'turn_id'            => $this->turnId,
            'model'              => $model,
            'latency_ms'         => $latencyMs,
            'input_tokens'       => $inputTokens,
            'output_tokens'      => $outputTokens,
            'cached_tokens'      => $cachedTokens,
            'total_tokens'       => ($inputTokens ?? 0) + ($outputTokens ?? 0),
            'estimated_cost_usd' => $estimatedCostUsd,
            'success'            => $success,
            'outcome'            => $outcome,
            'error'              => $errorMessage,
            'created_at'         => now()->toIso8601String(),
        ];

        DB::table('agent_metrics')->insert($metric);

        $this->turnMetrics[] = $metric;

        if ($success) {
            Log::info('[AgentOps] Agent call recorded', $metric);
        } else {
            Log::error('[AgentOps] Agent call recorded', $metric);
        }

        if ($outcome === 'clarifying') {
            Log::warning('[AgentOps] Agent asked a clarifying question instead of completing', [
                'intent'          => $intent,
                'user_id'         => $userId,
                'conversation_id' => $conversationId,
                'action'          => 'Review agent instructions — ReWOO plan-first workflow may not be triggering lookups.',
            ]);
        }
    }

    /**
     * Record the summary for the entire chat turn.
     * Call at the end of ChatOrchestrator::executeDispatch() after all agents complete.
     * Resets the internal buffer for the next turn (defensive reset; primary reset is in setTurnId).
     */
    public function recordTurnSummary(
        string  $userId,
        ?string $conversationId,
        array   $intents,
        int     $totalLatencyMs,
    ): void {
        $totalTokens    = array_sum(array_column($this->turnMetrics, 'total_tokens'));
        $totalCostUsd   = array_sum(array_column($this->turnMetrics, 'estimated_cost_usd'));
        $failedAgents   = array_filter($this->turnMetrics, fn($m): bool => !$m['success']);
        $agentLatencies = array_column($this->turnMetrics, 'latency_ms');

        $outcomesRaw  = array_column($this->turnMetrics, 'outcome');
        $hasAnySignal = count(array_filter($outcomesRaw, fn($o) => $o !== null)) > 0;
        $outcomes     = array_count_values(array_filter($outcomesRaw));

        $completionRate = (!$hasAnySignal)
            ? null
            : (count($this->turnMetrics) > 0
                ? round(($outcomes['completed'] ?? 0) / count($this->turnMetrics) * 100, 1)
                : null);

        Log::info('[AgentOps] Turn summary', [
            'user_id'              => $userId,
            'conversation_id'      => $conversationId,
            'intents'              => $intents,
            'agent_count'          => count($intents),
            'total_latency_ms'     => $totalLatencyMs,
            'agent_latencies_ms'   => $agentLatencies,
            'total_tokens'         => $totalTokens,
            'total_cost_usd'       => round($totalCostUsd, 6),
            'failed_agent_count'   => count($failedAgents),
            'all_succeeded'        => count($failedAgents) === 0,
            'outcome_distribution' => $outcomes,
            'completion_rate_pct'  => $completionRate,
            'per_agent'            => $this->turnMetrics,
        ]);

        DB::table('agent_turn_metrics')->insert([
            'turn_id'              => $this->turnId,
            'conversation_id'      => $conversationId,
            'user_id'              => $userId,
            'agent_count'          => count($intents),
            'total_latency_ms'     => $totalLatencyMs,
            'total_tokens'         => $totalTokens,
            'total_cost_usd'       => $totalCostUsd,
            'failed_agent_count'   => count($failedAgents),
            'all_succeeded'        => count($failedAgents) === 0,
            'completion_rate_pct'  => $completionRate,
            'outcome_distribution' => json_encode($outcomes),
            'intents'              => json_encode($intents),
            'created_at'           => now(),
        ]);

        // Defensive reset — primary reset is now in setTurnId()
        $this->turnMetrics = [];
        $this->turnId      = null;
    }

    public function getTurnMetrics(): array
    {
        return $this->turnMetrics;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Estimate USD cost for an agent call.
     *
     * Cached tokens are billed at 50% of the standard input rate. They are
     * already counted within $inputTokens, so we split the billing:
     * non-cached tokens at full rate, cached tokens at half rate.
     */
    private function estimateCost(
        string $model,
        ?int   $inputTokens,
        ?int   $outputTokens,
        ?int   $cachedTokens = null,
    ): float {
        $rates = self::MODEL_COSTS[$model] ?? null;

        if (!$rates || $inputTokens === null || $outputTokens === null) {
            return 0.0;
        }

        $cached    = $cachedTokens ?? 0;
        $nonCached = max(0, $inputTokens - $cached);

        return round(
            ($nonCached / 1000 * $rates['input'])  +
            ($cached    / 1000 * $rates['cached']) +
            ($outputTokens / 1000 * $rates['output']),
            6
        );
    }
}
