<?php

namespace App\Ai\Services;

use App\Ai\AgentRegistry;
use App\Ai\Agents\RouterAgent;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;

/**
 * IntentRouterService  (v4 — Bug 3 fix: dead $lower removed from needsVoting)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v3 (voting version)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * BUG 3 FIX — dead $lower variable in needsVoting():
 *
 *   The previous version computed:
 *     $lower = strtolower($message);
 *   and then never used it. Every subsequent preg_match() call used $message
 *   directly (with the /i case-insensitive flag), making $lower pure dead code.
 *
 *   Fix: remove $lower entirely. All pattern matches already use /i so no
 *   behaviour change — this is a code cleanliness fix.
 *
 * All voting-version changes preserved:
 *   - VOTE_COUNT = 3, MAJORITY_THRESHOLD = 2
 *   - needsVoting() gates parallel voting on ambiguous/multi-intent messages
 *   - runParallelVotes() uses Concurrency::run() for the 2 additional calls
 *   - applyMajorityVote() with fallback to first vote on total disagreement
 *   - looksLikeFollowUp() fast-path unchanged
 *   - AgentRegistry-driven validDomainIntents()
 */
class IntentRouterService
{
    private const VOTE_COUNT         = 3;
    private const MAJORITY_THRESHOLD = 2;

    public function __construct(private readonly RouterAgent $router) {}

    /**
     * @return string[]
     */
    public function validDomainIntents(): array
    {
        return AgentRegistry::validIntents();
    }

    /**
     * Route a user message to one or more domain intents.
     *
     * Fast path:  follow-up messages → [] (no router call)
     * Normal:     single unambiguous result → return directly
     * Voting:     ambiguous/multi-intent → 3-way parallel vote, majority wins
     *
     * @return string[]
     */
    public function resolve(string $message, ?string $conversationId): array
    {
        if ($this->looksLikeFollowUp($message)) {
            Log::info('[IntentRouterService] Follow-up detected, deferring to DB fallback', [
                'message' => mb_substr($message, 0, 80),
            ]);
            return [];
        }

        $firstResult = $this->callRouter($message);
        $firstResult = array_values(array_filter($firstResult, fn($i) => $i !== 'unknown'));

        if (empty($firstResult)) {
            return [];
        }

        if (!$this->needsVoting($message, $firstResult)) {
            Log::info('[IntentRouterService] Single confident intent — skipping vote', [
                'intents' => $firstResult,
            ]);
            return $firstResult;
        }

        Log::info('[IntentRouterService] Ambiguous classification — running majority vote', [
            'message_preview' => mb_substr($message, 0, 80),
            'first_result'    => $firstResult,
            'vote_count'      => self::VOTE_COUNT,
        ]);

        $additionalVotes = $this->runParallelVotes($message, self::VOTE_COUNT - 1);
        $allVotes        = [$firstResult, ...$additionalVotes];
        $majority        = $this->applyMajorityVote($allVotes, $firstResult);

        Log::info('[IntentRouterService] Voting complete', [
            'all_votes' => $allVotes,
            'majority'  => $majority,
        ]);

        return $majority;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Determine whether a first-pass result warrants majority voting.
     *
     * BUG 3 FIX: removed the dead `$lower = strtolower($message)` line.
     * All preg_match() calls already use the /i flag and operate on $message
     * directly, so $lower was unused. No behaviour change.
     */
    private function needsVoting(string $message, array $intents): bool
    {
        // Always vote on multi-intent — hardest classification, costliest mistake.
        if (count($intents) > 1) {
            return true;
        }

        // Client + invoice boundary
        $hasClientSignal  = preg_match('/\b(new client|just onboarded|new customer|first time)\b/i', $message);
        $hasInvoiceSignal = preg_match('/\b(invoice|bill|receipt)\b/i', $message);
        if ($hasClientSignal && $hasInvoiceSignal) {
            return true;
        }

        // Inventory + invoice boundary
        $hasProductSignal = preg_match('/\b(add|create|new)\b.{0,20}\b(item|product|service)\b/i', $message);
        if ($hasProductSignal && $hasInvoiceSignal) {
            return true;
        }

        // Narration + bank_transaction co-routing boundary
        $hasNarrationSignal   = preg_match('/\b(head|narration|category|ledger)\b/i', $message);
        $hasTransactionSignal = preg_match('/\b(transaction|bank|payment|debit|credit)\b/i', $message);
        if ($hasNarrationSignal && $hasTransactionSignal) {
            return true;
        }

        return false;
    }

    /**
     * Run N additional RouterAgent calls in parallel via Concurrency::run().
     *
     * @return array<int, string[]>
     */
    private function runParallelVotes(string $message, int $count): array
    {
        $tasks = [];

        for ($i = 0; $i < $count; $i++) {
            $tasks[] = function () use ($message): array {
                $result = $this->callRouter($message);
                return array_values(array_filter($result, fn($i) => $i !== 'unknown'));
            };
        }

        /** @var array<int, string[]> $results */
        return Concurrency::run($tasks);
    }

    /**
     * Apply majority voting. Intents surviving >= MAJORITY_THRESHOLD votes win.
     * Falls back to $fallback when no intent reaches the threshold.
     *
     * @param  array<int, string[]> $votes
     * @param  string[]             $fallback
     * @return string[]
     */
    private function applyMajorityVote(array $votes, array $fallback): array
    {
        $intentCounts = [];

        foreach ($votes as $voteIntents) {
            foreach ($voteIntents as $intent) {
                $intentCounts[$intent] = ($intentCounts[$intent] ?? 0) + 1;
            }
        }

        $majority = array_keys(array_filter(
            $intentCounts,
            fn($count) => $count >= self::MAJORITY_THRESHOLD
        ));

        if (empty($majority)) {
            Log::warning('[IntentRouterService] No majority reached — falling back to first vote', [
                'all_votes'     => $votes,
                'intent_counts' => $intentCounts,
                'fallback'      => $fallback,
            ]);
            return $fallback;
        }

        usort($majority, function ($a, $b) use ($intentCounts) {
            $diff = $intentCounts[$b] - $intentCounts[$a];
            return $diff !== 0 ? $diff : strcmp($a, $b);
        });

        return $majority;
    }

    private function looksLikeFollowUp(string $message): bool
    {
        $wordCount = str_word_count(strtolower($message));

        $hasAccountingNoun = preg_match(
            '/\b(invoice|invoices|client|clients|inventory|product|
        narration|business|bank|transaction|transactions|
        head|sub.?head)\b/ix',
            $message
        );

        if ($wordCount <= 8 && !$hasAccountingNoun) {
            return true;
        }

        $hasActionVerb = preg_match(
            '/^\s*(create|make|generate|show|list|view|find|search|
            add|update|edit|delete|remove|void|cancel|send|
            fetch|get|give|issue|record|mark|flag|reconcile|approve)\b/ix',
            $message
        );

        $hasFieldValuePattern = preg_match(
            '/\b(is|are|code|number|type|rate|terms?|limit|notes?|
        stock|unit|hsn|sac|pincode|country|city|state|
        currency|phone|email|address|payment|gst|pan)\b.{0,20}
        [\d₹@+\/\-]/ix',
            $message
        );

        if (!$hasActionVerb && $hasFieldValuePattern) {
            Log::info('[IntentRouterService] Answer-pattern follow-up detected', [
                'message' => mb_substr($message, 0, 80),
            ]);
            return true;
        }

        return false;
    }

    private function callRouter(string $message): array
    {
        try {
            $response = $this->router->prompt(prompt: $message);
            $intents  = $this->parseIntents(trim((string) $response));
            return $this->filterValid($intents);
        } catch (\Throwable $e) {
            Log::error('[IntentRouterService] RouterAgent call failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    private function parseIntents(string $raw): array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned ?? $raw);
        $cleaned = trim($cleaned ?? $raw);

        try {
            $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[IntentRouterService] JSON parse failed', [
                'raw'   => $raw,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (!isset($decoded['intents']) || !is_array($decoded['intents'])) {
            Log::warning('[IntentRouterService] Missing or invalid intents key', [
                'decoded' => $decoded,
            ]);
            return [];
        }

        return $decoded['intents'];
    }

    private function filterValid(array $intents): array
    {
        return array_values(
            array_unique(
                array_intersect($intents, AgentRegistry::validIntents())
            )
        );
    }
}
