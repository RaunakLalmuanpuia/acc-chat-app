<?php

namespace App\Ai\Services;

/**
 * AgentContextBlackboard  (v2 — Fix 2: seedFrom() for retry-pass context continuity)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v1
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 2 — retry pass lost Pass 1 blackboard context:
 *
 *   executeDispatch() calls dispatchAll() twice: once for Pass 1 and once for
 *   the evaluator retry. Both calls previously created a brand-new blackboard,
 *   so the retry agents had no visibility into what Pass 1 agents completed.
 *   InvoiceAgent's BLACKBOARD DEPENDENCY CHECK saw an empty PRIOR AGENT CONTEXT
 *   block and fell through to its "waiting" path even when ClientAgent had
 *   already succeeded.
 *
 *   Fix: add seedFrom() so the retry blackboard can be pre-populated with
 *   all Pass 1 context before any retry agent is dispatched.
 *
 *   New public API:
 *     seedFrom(AgentContextBlackboard $other): void
 *       — copies all $state and $meta from $other into $this
 *     allMeta(): array
 *       — exposes $meta for seedFrom() and debugging
 *
 * IBM alignment (all v1 features preserved):
 *   - "Agents model each other's goals and memory" (IBM MAS definition)
 *   - "Communication through altering the shared environment"
 *   - Turn-isolated lifecycle (fresh instance per turn in dispatchAll)
 */
class AgentContextBlackboard
{
    /**
     * @var array<string, array{reply: string, recorded_at: string}>
     */
    private array $state = [];

    private array $meta = [];

    // ── Recording ──────────────────────────────────────────────────────────────

    /**
     * Record a completed agent's reply onto the blackboard.
     *
     * @param  string $intent  The domain intent (e.g. 'client', 'invoice')
     * @param  string $reply   The agent's natural-language response
     */
    public function record(string $intent, string $reply): void
    {
        $this->state[$intent] = [
            'reply'       => $reply,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    // ── FIX 2 — cross-pass seeding ─────────────────────────────────────────────

    /**
     * Seed this blackboard from another, copying all recorded state and meta.
     *
     * Called by dispatchAll() when a priorBlackboard is passed for the retry
     * pass. The retry agents will see the same PRIOR AGENT CONTEXT block that
     * primary agents saw in Pass 1, preserving IDs, ✅ markers, and pending ⏳
     * signals so InvoiceAgent's BLACKBOARD DEPENDENCY CHECK works correctly.
     *
     * seedFrom() is additive — any state already in $this is preserved and
     * entries from $other are merged in. In practice, $this is always a fresh
     * instance when seedFrom() is called so this distinction rarely matters.
     */
    public function seedFrom(AgentContextBlackboard $other): void
    {
        foreach ($other->all() as $intent => $entry) {
            $this->state[$intent] = $entry;
        }

        foreach ($other->allMeta() as $key => $value) {
            $this->meta[$key] = $value;
        }
    }

    // ── Querying ───────────────────────────────────────────────────────────────

    /**
     * Whether the blackboard has any recorded context.
     */
    public function isEmpty(): bool
    {
        return empty($this->state);
    }

    /**
     * Whether a specific intent has been recorded.
     */
    public function has(string $intent): bool
    {
        return isset($this->state[$intent]);
    }

    /**
     * Retrieve a specific agent's recorded reply.
     */
    public function getReply(string $intent): ?string
    {
        return $this->state[$intent]['reply'] ?? null;
    }

    // ── Meta (structured IDs) ─────────────────────────────────────────────────

    public function setMeta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * Expose the full meta map for seedFrom() and debugging.
     */
    public function allMeta(): array
    {
        return $this->meta;
    }

    // ── Context preamble ──────────────────────────────────────────────────────

    /**
     * Build a context preamble for a given specialist agent.
     *
     * Includes all prior agents' replies except the current one.
     * Injected at the top of the specialist's prompt so it treats prior
     * work as established fact — no redundant lookups.
     *
     * Returns an empty string if no prior context exists (single-intent turns
     * or the first agent in a multi-intent sequence).
     *
     * @param  string $forIntent  The intent about to be dispatched
     * @return string
     */
    public function buildContextPreamble(string $forIntent): string
    {
        $priorIntents = array_filter(
            array_keys($this->state),
            fn (string $i): bool => $i !== $forIntent
        );

        if (empty($priorIntents)) {
            return '';
        }

        $lines = [
            '╔══════════════════════════════════════════════════════════════╗',
            '║  PRIOR AGENT CONTEXT — treat as established fact             ║',
            '║  Do NOT re-fetch, re-create, or contradict this information. ║',
            '║  CRITICAL: This context was ALREADY shown to the user.       ║',
            '║  Do NOT repeat, summarise, or mention it in your reply.      ║',
            '║  If a prior agent asked for missing info, that resource does ║',
            '║  NOT exist yet. Tell the user to complete that step first.   ║',
            '║  Use it silently for lookups and decisions only.             ║',
            '╚══════════════════════════════════════════════════════════════╝',
            '',
        ];

        foreach ($priorIntents as $intent) {
            $lines[] = "── [{$intent} agent completed] ──────────────────────────────────";
            $lines[] = $this->state[$intent]['reply'];
            $lines[] = "── [end {$intent} context] ──────────────────────────────────────";
            $lines[] = '';
        }

        if ($forIntent === 'invoice') {
            $clientId = $this->getMeta('client_id');
            $itemId   = $this->getMeta('inventory_item_id');

            if ($clientId || $itemId) {
                $lines[] = '── [resolved IDs — use directly, skip lookup tools] ────────────';
                if ($clientId) {
                    $lines[] = "⚙ client_id = {$clientId} → pass directly to create_invoice. Do NOT call lookup_client.";
                }
                if ($itemId) {
                    $lines[] = "⚙ inventory_item_id = {$itemId} → pass directly to add_line_item. Do NOT call lookup_inventory_item.";
                }
                $lines[] = '── [end resolved IDs] ───────────────────────────────────────────';
                $lines[] = '';
            }
        }

        if ($forIntent === 'bank_transaction') {
            $headId    = $this->getMeta('narration_head_id');
            $subHeadId = $this->getMeta('narration_sub_head_id');

            if ($headId || $subHeadId) {
                $lines[] = '── [resolved IDs — use directly, skip lookup tools] ────────────';
                if ($headId) {
                    $lines[] = "⚙ narration_head_id = {$headId} → pass directly to narrate_transaction.";
                }
                if ($subHeadId) {
                    $lines[] = "⚙ narration_sub_head_id = {$subHeadId} → pass directly to narrate_transaction.";
                }
                $lines[] = '── [end resolved IDs] ───────────────────────────────────────────';
                $lines[] = '';
            }
        }

        $lines[] = '════════════════════════════════════════════════════════════════';
        $lines[] = '';

        return implode("\n", $lines);
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    /**
     * Return the full blackboard state for observability / debugging.
     */
    public function all(): array
    {
        return $this->state;
    }
}
