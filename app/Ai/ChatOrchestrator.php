<?php

namespace App\Ai;

use App\Ai\AgentRegistry;
use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\EvaluatorService;
use App\Ai\Services\HitlService;
use App\Ai\Services\IntentRouterService;
use App\Ai\Services\ObservabilityService;
use App\Ai\Services\ResponseMergerService;
use App\Ai\Services\ScopeGuardService;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ChatOrchestrator  (v12 — per-session scoped IDs extended to InvoiceAgent)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v10
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v12 — per-session scoped IDs extended to InvoiceAgent:
 *
 *   InvoiceAgent now also uses {base}:{setupTurnGroupId}:invoice instead of the
 *   shared {base}:invoice. Root cause of the bug: after 3+ sessions, InvoiceAgent's
 *   accumulated conversation history contained prior add_line_item calls with
 *   stale inventory_item_ids. Even with the correct ID in the [resolved IDs]
 *   blackboard block, the model pattern-matched the history value instead.
 *
 *   getLastIntents() updated: lastInvoiceMessage and invoiceAlreadyCreated searches
 *   now scan all `:invoice`-suffixed scopes in $rows (instead of the fixed
 *   {base}:invoice key). The non-invoice context check uses str_ends_with(':invoice').
 *
 *   loadActiveInvoiceNumber() updated: both DB queries now include
 *   LIKE '{base}:%:invoice' so they find invoices in per-session scoped
 *   conversations as well as old {base}:invoice conversations (backward compat).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v10 (v11)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v11 — per-session scoped IDs for setup agents:
 *
 *   Setup agents (client/inventory/narration) now use {base}:{setupTurnGroupId}:{intent}
 *   as their scoped conversation ID instead of the shared {base}:{intent}. Each invoice
 *   creation session gets an isolated conversation history, preventing cross-session
 *   hallucination where accumulated history caused InventoryAgent to predict item IDs
 *   without calling the actual API.
 *
 *   setupTurnGroupId = current turnId on router-resolved turns (new session).
 *   setupTurnGroupId = turn_group_id from last multi-intent group meta on DB-fallback
 *   turns (follow-up), ensuring turn-1 → turn-2 rate-collection continuity.
 *
 *   turn_group_id is written to setup agent message meta by writeMetaToMessage(),
 *   always equal to the original session's setupTurnGroupId, so getLastIntents()
 *   can reconstruct the correct session scope on follow-up turns.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v9 (v10)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v10 — FIX A: skipToSingleIntent false-positive for setup intents:
 *
 *   The "Non-invoice context more recent" check was treating :client,
 *   :inventory, and :narration scoped messages as competing intents and
 *   incorrectly setting skipToSingleIntent=true. Fixed by excluding
 *   $invoiceSetupIntents from the timestamp check.
 *
 * v10 — FIX B: secondary/primary pruning matched old-session completion markers:
 *
 *   The pruning loop checked ALL historical messages (no timestamp scope),
 *   so a [CLIENT_ID:] from a prior Infosys session would mark "client done"
 *   for a new ABC Company request, dropping client from dispatch and returning
 *   ['invoice'] alone. Root cause confirmed from log:
 *     "Setup intents complete — primary only {"dropped":["inventory","client"]}"
 *   Fix: scope the check to $r->created_at >= $lastMultiMessage->created_at,
 *   matching the allSetupDone check immediately above it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v8 (v9)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v9 — parallel ScopeGuard + router (Anthropic Sectioning pattern):
 *
 *   ScopeGuard::evaluate() and IntentRouterService::resolve() now run
 *   concurrently via Concurrency::run(). ScopeGuard is pure-regex (~0ms)
 *   so the latency win on the happy path is negligible today, but the
 *   structure is in place for any future LLM-based guard upgrade.
 *   Trade-off: blocked requests pay one extra router call (gpt-4o-mini,
 *   cheap) — accepted since blocks are rare in production.
 *
 * All v8 changes preserved (FIX 1, FIX 2, FIX 5, FIX 9, FIX 12, FIX 16):
 *
 * FIX 1 — active invoice number never loaded for router-resolved turns:
 *
 *   loadActiveInvoiceNumber() was buried inside getLastIntents(), which is only
 *   called when the router returns EMPTY intents (the DB fallback path). On
 *   continuation turns like "add another item" or "generate the PDF", the router
 *   correctly resolves ['invoice'] — so getLastIntents() was never called,
 *   $activeInvoiceNumber stayed null, and the ACTIVE INVOICE hint block was
 *   never injected into InvoiceAgent's prompt. InvoiceAgent fell through to
 *   get_active_drafts() on every continuation turn, adding a wasteful extra
 *   tool round-trip.
 *
 *   Fix: after intent resolution (both router path and DB-fallback path), call
 *   loadActiveInvoiceNumber() when 'invoice' is among the resolved intents.
 *
 * FIX 2 — evaluator retry lost Pass 1 blackboard context:
 *
 *   dispatchAll() now returns ['responses' => ..., 'blackboard' => ...].
 *   The orchestrator captures the Pass 1 blackboard and passes it to the retry
 *   call as $priorBlackboard so retry agents receive the correct
 *   ╔══ PRIOR AGENT CONTEXT ══╗ block with ✅ markers from completed agents.
 *
 * FIX 5 — $turnId defaulted to '' in executeDispatch signature:
 *
 *   The parameter was typed as `string $turnId = ''`. An empty string passes
 *   the `!== null` guard in writeMetaToMessage(), writing turn_id="" to the
 *   DB and potentially matching the wrong message group in getLastIntents().
 *   Fixed to `?string $turnId = null`; writeMetaToMessage() companion guard
 *   uses !empty() to filter both null and empty string.
 *
 * FIX 9 — getLastIntents() made 5–10 sequential DB queries:
 *
 *   All state needed by getLastIntents() is now fetched in a single query.
 *   The result set is indexed in PHP and all intent-state decisions are made
 *   in-memory, eliminating the cascading EXISTS + per-intent LIKE queries.
 *
 * FIX 12 — scope guard early return was missing the 'pending_id' key:
 *
 *   Every other return path includes all five chatResponse keys. The scope guard
 *   path returned only four (no 'pending_id'). AiChatController uses ?? null so
 *   it didn't break, but the inconsistent shape caused confusion and required
 *   null-guarding on the frontend. Added 'pending_id' => null.
 *
 * FIX 16 — misleading evaluate(isRetry: true) call after retry pass:
 *
 *   Replaced with EvaluatorService::logFinalOutcome() which is a pure logging
 *   method with no retry logic, making the orchestrator easier to read.
 *
 * All v7 changes preserved:
 *   - BUG 2 FIX: evaluator gated on multi-intent turns only
 *   - buildPlanSummary() for multi-intent transparency
 *   - FIX 4 scope fix ($skipToSingleIntent computed once)
 *   - FIX 1 invoice timestamp wins (original v6 fix, now in rewritten getLastIntents)
 *   - FIX 2 content-based completion check (original v6 fix)
 *   - Secondary/primary pruning
 */
class ChatOrchestrator
{
    private ?string $activeInvoiceNumber = null;

    public function __construct(
        private readonly IntentRouterService    $router,
        private readonly AgentDispatcherService $dispatcher,
        private readonly ResponseMergerService  $merger,
        private readonly HitlService            $hitl,
        private readonly ObservabilityService   $observability,
        private readonly ScopeGuardService      $scopeGuard,
        private readonly EvaluatorService       $evaluator,
    ) {}

    public function handle(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $attachments = [],
    ): array {
        $turnStart = microtime(true);
        $turnId    = Str::uuid()->toString();
        $this->observability->setTurnId($turnId);
        $this->activeInvoiceNumber = null;

        // Run ScopeGuard and the intent router in parallel.
        // ScopeGuard is pure-regex (zero latency) but parallelising now means
        // any future LLM-based guard upgrade costs nothing in latency.
        // Trade-off: blocked requests pay one extra router call; accepted since
        // blocked requests are rare in production and the router model is cheap.
        [$guardResult, $intents] = Concurrency::run([
            fn () => $this->scopeGuard->evaluate($message, (string) $user->id),
            fn () => $this->router->resolve($message, $conversationId),
        ]);

        if (!$guardResult->allowed) {
            return [
                'reply'           => $guardResult->response,
                'conversation_id' => $conversationId,
                'hitl_pending'    => false,
                'pending_id'      => null,   // FIX 12: consistent shape with all other returns
                'plan'            => null,
            ];
        }

        Log::info('[ChatOrchestrator] Handling message', [
            'user_id'         => $user->id,
            'conversation_id' => $conversationId,
            'message_preview' => mb_substr($message, 0, 80),
        ]);

        Log::info('[ChatOrchestrator] Resolved intents', ['intents' => $intents]);

        // Each new router-resolved multi-intent turn gets a fresh setupTurnGroupId (= current
        // turnId). DB-fallback follow-up turns inherit the group ID from the last multi-intent
        // group so setup agents continue the same session-scoped conversation.
        $setupTurnGroupId = $turnId;

        if (empty($intents) && $conversationId !== null) {
            ['intents' => $lastIntents, 'turnGroupId' => $lastTurnGroupId] = $this->getLastIntents($conversationId);

            if (!empty($lastIntents)) {
                Log::info('[ChatOrchestrator] Reusing previous intents from DB', [
                    'conversation_id' => $conversationId,
                    'intents'         => $lastIntents,
                ]);
                $intents          = $lastIntents;
                $setupTurnGroupId = $lastTurnGroupId ?? $turnId;
            }
        }

        if (empty($intents)) {
            return [
                'reply'           => $this->merger->unknownResponse(),
                'conversation_id' => $conversationId,
                'hitl_pending'    => false,
                'pending_id'      => null,
                'plan'            => null,
            ];
        }

        // FIX 1 — load the active invoice number for ALL turns that include
        // the invoice intent, not just DB-fallback turns. The router correctly
        // resolves ['invoice'] on continuation turns, which previously left
        // $activeInvoiceNumber null and forced InvoiceAgent to call
        // get_active_drafts() on every continuation.
        if (in_array('invoice', $intents) && $conversationId !== null) {
            $this->loadActiveInvoiceNumber($conversationId);
        }

        if ($this->hitl->requiresCheckpoint($message, $intents)) {
            $pendingId = $this->hitl->storePendingAction(
                userId:         (string) $user->id,
                message:        $message,
                intents:        $intents,
                conversationId: $conversationId,
            );

            Log::info('[ChatOrchestrator] HITL checkpoint triggered', [
                'user_id'    => $user->id,
                'intents'    => $intents,
                'pending_id' => $pendingId,
            ]);

            return [
                'reply'           => $this->hitl->buildCheckpointMessage($message, $intents),
                'conversation_id' => $conversationId,
                'hitl_pending'    => true,
                'pending_id'      => $pendingId,
                'plan'            => null,
            ];
        }

        $plan = count($intents) > 1 ? $this->buildPlanSummary($intents) : null;

        return $this->executeDispatch(
            user:             $user,
            message:          $message,
            conversationId:   $conversationId,
            intents:          $intents,
            attachments:      $attachments,
            turnStart:        $turnStart,
            turnId:           $turnId,
            plan:             $plan,
            setupTurnGroupId: $setupTurnGroupId,
        );
    }

    public function confirm(
        User   $user,
        string $pendingId,
        array  $attachments = [],
    ): array {
        $turnStart = microtime(true);
        $turnId    = Str::uuid()->toString();
        $this->observability->setTurnId($turnId);
        $this->activeInvoiceNumber = null;

        $action = $this->hitl->consumePendingAction($pendingId);

        if ($action === null) {
            Log::warning('[ChatOrchestrator] HITL action not found or expired', [
                'user_id'    => $user->id,
                'pending_id' => $pendingId,
            ]);
            return [
                'reply'           => "This confirmation has expired (15-minute limit). Please re-send your original request.",
                'conversation_id' => null,
                'hitl_pending'    => false,
                'plan'            => null,
            ];
        }

        if ((string) $user->id !== (string) $action['user_id']) {
            Log::warning('[ChatOrchestrator] HITL ownership mismatch', [
                'requesting_user' => $user->id,
                'action_user'     => $action['user_id'],
                'pending_id'      => $pendingId,
            ]);
            return [
                'reply'           => "You are not authorized to confirm this action.",
                'conversation_id' => null,
                'hitl_pending'    => false,
                'plan'            => null,
            ];
        }

        Log::info('[ChatOrchestrator] HITL confirmed — re-dispatching', [
            'user_id'    => $user->id,
            'pending_id' => $pendingId,
            'intents'    => $action['intents'],
        ]);

        return $this->executeDispatch(
            user:           $user,
            message:        $action['message'],
            conversationId: $action['conversation_id'],
            intents:        $action['intents'],
            attachments:    $attachments,
            turnStart:      $turnStart,
            turnId:         $turnId,
            hitlConfirmed:  true,
        );
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Dispatch agents and apply the evaluator-optimizer loop (multi-intent only).
     *
     * FIX 2: dispatchAll() now returns ['responses' => ..., 'blackboard' => ...].
     * The Pass 1 blackboard is captured and forwarded to the retry call so
     * retry agents receive the correct PRIOR AGENT CONTEXT preamble.
     *
     * FIX 5: $turnId is ?string = null (was: string = '') to prevent empty
     * string from being written as turn_id to the DB.
     *
     * FIX 16: Post-retry evaluation replaced with logFinalOutcome() which is
     * a pure logging method with no retry side-effects.
     */
    private function executeDispatch(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $intents,
        array   $attachments,
        float   $turnStart,
        ?string $turnId           = null,   // FIX 5: was string = ''
        bool    $hitlConfirmed    = false,
        ?string $plan             = null,
        ?string $setupTurnGroupId = null,
    ): array {
        // ── Pass 1 ─────────────────────────────────────────────────────────────
        // FIX 2: destructure to capture the blackboard for potential retry use
        [
            'responses'  => $responses,
            'blackboard' => $pass1Blackboard,
        ] = $this->dispatcher->dispatchAll(
            intents:             $intents,
            user:                $user,
            message:             $message,
            conversationId:      $conversationId,
            attachments:         $attachments,
            hitlConfirmed:       $hitlConfirmed,
            turnId:              $turnId ?? '',
            activeInvoiceNumber: $this->activeInvoiceNumber,
            setupTurnGroupId:    $setupTurnGroupId,
        );

        // ── Evaluator-optimizer (multi-intent turns only) ─────────────────────
        //
        // Completion markers are contractual signals emitted only in multi-agent
        // coordination turns. Single-intent agents never emit [CLIENT_ID:N] in
        // standalone responses, so the evaluator would always see isCompleted=false
        // and fire a spurious retry on every single-intent turn.
        if (count($intents) > 1) {
            $evaluation = $this->evaluator->evaluate(
                responses: $responses,
                intents:   $intents,
                message:   $message,
                isRetry:   false,
            );

            // ── Pass 2 (conditional, one retry max) ───────────────────────────
            if ($evaluation->shouldRetry) {
                Log::info('[ChatOrchestrator] Evaluator triggered retry', [
                    'intents_to_retry' => $evaluation->intentsToRetry,
                ]);

                $retryMessage = $evaluation->augmentation . $message;

                // FIX 2: pass Pass 1 blackboard so retry agents see completed
                // agents' ✅ markers and resolved IDs in PRIOR AGENT CONTEXT
                [
                    'responses' => $retryResponses,
                ] = $this->dispatcher->dispatchAll(
                    intents:             $evaluation->intentsToRetry,
                    user:                $user,
                    message:             $retryMessage,
                    conversationId:      $conversationId,
                    attachments:         $attachments,
                    hitlConfirmed:       $hitlConfirmed,
                    turnId:              $turnId ?? '',
                    activeInvoiceNumber: $this->activeInvoiceNumber,
                    priorBlackboard:     $pass1Blackboard,   // FIX 2
                    setupTurnGroupId:    $setupTurnGroupId,
                );

                foreach ($evaluation->intentsToRetry as $intent) {
                    if (isset($retryResponses[$intent])) {
                        $responses[$intent] = $retryResponses[$intent];
                    }
                }

                // FIX 16: use logFinalOutcome() instead of evaluate(isRetry: true).
                // This is a pure logging call — no retry decision is made.
                $this->evaluator->logFinalOutcome(
                    responses: $responses,
                    intents:   $intents,
                    message:   $message,
                );
            }
        }

        // ── Resolve conversation ID and build reply ────────────────────────────
        $first = !empty($responses) ? reset($responses) : [];
        $rawId = $conversationId ?? ($first['conversation_id'] ?? null);

        $newConversationId = $rawId !== null
            ? explode(':', $rawId)[0]
            : null;

        $replyStrings = array_map(fn($r) => $r['reply'], $responses);
        $reply        = $this->merger->merge($replyStrings);

        if (trim($reply) === '') {
            $reply = "I'm ready to continue — what would you like to do next?";
        }

        $totalLatencyMs = (int) ((microtime(true) - $turnStart) * 1000);
        $this->observability->recordTurnSummary(
            userId:         (string) $user->id,
            conversationId: $newConversationId,
            intents:        $intents,
            totalLatencyMs: $totalLatencyMs,
        );

        return [
            'reply'           => $reply,
            'conversation_id' => $newConversationId,
            'hitl_pending'    => false,
            'plan'            => $plan,
        ];
    }

    /**
     * Build a deterministic planning summary for multi-intent turns.
     * Zero latency — no LLM call.
     *
     * @param  string[] $intents
     */
    private function buildPlanSummary(array $intents): string
    {
        $stepLabels = [
            'client'           => 'look up or create the client',
            'inventory'        => 'look up or create the inventory item',
            'narration'        => 'set up the narration head',
            'invoice'          => 'create and configure the invoice',
            'bank_transaction' => 'process the bank transaction',
            'business'         => 'update the business profile',
        ];

        $steps = array_map(
            fn($intent) => $stepLabels[$intent] ?? "handle {$intent}",
            $intents
        );

        if (count($steps) <= 1) {
            return '';
        }

        $last    = array_pop($steps);
        $listStr = empty($steps)
            ? $last
            : implode(', ', $steps) . ', then ' . $last;

        return "I'll {$listStr}.";
    }

    // ── getLastIntents (Fix 9: single batched query) ──────────────────────────

    /**
     * Determine the most relevant intents from conversation history.
     *
     * FIX 9 — replaced 5-10 sequential DB queries with a single fetch.
     *
     * v7 made cascading queries: lastInvoiceMessage, lastMultiMessage,
     * newerNonInvoice existence check, per-setup-intent completion EXISTS
     * queries, secondary/primary pruning EXISTS queries. On a 3-setup-intent
     * turn this totalled up to 10 queries before any agent work started.
     *
     * Fix: fetch all recent assistant messages across all scoped conversations
     * in ONE query (LIMIT 100, ordered desc). All state that was previously
     * derived from separate EXISTS queries is now computed in PHP from the
     * in-memory result set — grouping by conversation_id, inspecting meta JSON,
     * and checking content for completion markers.
     *
     * loadActiveInvoiceNumber() is still called when needed (it makes 1-2
     * targeted queries for the invoice scope only) — its own fix is tracked
     * separately. The N-query savings here are in the main intent-routing logic.
     */
    private function getLastIntents(string $conversationId): array // array{intents: string[], turnGroupId: ?string}
    {
        // ── SINGLE BATCHED QUERY ───────────────────────────────────────────────
        $rows = DB::table('agent_conversation_messages')
            ->where(function ($q) use ($conversationId) {
                $q->where('conversation_id', $conversationId)
                    ->orWhere('conversation_id', 'like', $conversationId . ':%');
            })
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['conversation_id', 'content', 'meta', 'created_at'])
            ->toArray();

        if (empty($rows)) {
            return ['intents' => [], 'turnGroupId' => null];
        }

        if (count($rows) === 100) {
            Log::warning('[ChatOrchestrator] getLastIntents hit LIMIT 100 — older messages may be missing', [
                'conversation_id' => $conversationId,
            ]);
        }

        // Index by conversation_id for O(1) scoped lookups in PHP
        $byConvo = [];
        foreach ($rows as $row) {
            $byConvo[$row->conversation_id][] = $row;
        }

        // ── Derive state (previously individual queries) ──────────────────────

        // lastInvoiceMessage: latest assistant message from any :invoice-scoped
        // conversation that has an invoice_number in meta. Scans $rows (DESC)
        // directly to cover both {base}:invoice (old) and {base}:{uuid}:invoice
        // (per-session scoped, v12+).
        $lastInvoiceMessage = null;
        foreach ($rows as $r) {
            if (!str_ends_with($r->conversation_id, ':invoice')) continue;
            $m = json_decode($r->meta ?? '{}', true);
            if (isset($m['invoice_number'])) {
                $lastInvoiceMessage = $r;
                break; // $rows is DESC — first hit is most recent
            }
        }

        // lastMultiMessage: latest assistant message anywhere in this conversation
        // family with multi_intent = true
        $lastMultiMessage = null;
        foreach ($rows as $r) {
            $m = json_decode($r->meta ?? '{}', true);
            if (!empty($m['multi_intent'])) {
                $lastMultiMessage = $r;
                break;
            }
        }

        // Extract the stable turn-group ID. Prefer 'turn_group_id' (written by setup agents
        // starting from v11) over 'turn_id' (fallback for backward compatibility).
        $turnGroupId = null;
        if ($lastMultiMessage !== null) {
            $lastMultiMeta = json_decode($lastMultiMessage->meta ?? '{}', true);
            $turnGroupId = $lastMultiMeta['turn_group_id'] ?? ($lastMultiMeta['turn_id'] ?? null);
        }

        // ── FIX 4: compute skip flag once ─────────────────────────────────────
        $skipToSingleIntent = false;

        // Setup agents accompany invoice creation flows. Messages from their scoped
        // conversations must NOT trigger the single-intent fallback — they belong
        // to the same invoice workflow, not a competing intent.
        // Derived from AgentRegistry so adding a new setup agent requires no edit here.
        $invoiceSetupIntents = AgentRegistry::setupIntents();

        if ($lastInvoiceMessage !== null) {
            // Is there any non-invoice, non-setup-intent scoped message newer
            // than the last confirmed invoice message?
            foreach ($rows as $r) {
                if (str_ends_with($r->conversation_id, ':invoice')) continue;
                if ($r->conversation_id === $conversationId) continue;

                $m = json_decode($r->meta ?? '{}', true);
                if (empty($m['intent'])) continue;

                // Setup intents are part of the invoice workflow — skip them
                if (in_array($m['intent'], $invoiceSetupIntents)) continue;

                if ($r->created_at > $lastInvoiceMessage->created_at) {
                    $skipToSingleIntent = true;
                    Log::info('[ChatOrchestrator] Non-invoice context more recent — skipping to single-intent fallback', [
                        'conversation_id' => $conversationId,
                        'competing_intent' => $m['intent'],
                    ]);
                    break;
                }
            }
        }

        // ── FIX 1 (original): invoice timestamp wins ──────────────────────────
        if (!$skipToSingleIntent
            && $lastInvoiceMessage !== null
            && $lastMultiMessage !== null
            && $lastInvoiceMessage->created_at > $lastMultiMessage->created_at
        ) {
            $this->loadActiveInvoiceNumber($conversationId);
            return ['intents' => ['invoice'], 'turnGroupId' => $turnGroupId];
        }

        // ── Multi-intent group logic ──────────────────────────────────────────
        if (!$skipToSingleIntent && $lastMultiMessage !== null) {
            $meta   = json_decode($lastMultiMessage->meta ?? '{}', true);
            $turnId = $meta['turn_id'] ?? null;

            // Collect all multi-intent messages from the same turn
            // (matched by turn_id, or by a ±2 second timestamp window as fallback)
            $multiRows = [];
            $multiTs   = strtotime($lastMultiMessage->created_at);

            foreach ($rows as $r) {
                $m = json_decode($r->meta ?? '{}', true);
                if (empty($m['multi_intent'])) continue;

                if ($turnId !== null) {
                    if (($m['turn_id'] ?? null) === $turnId) {
                        $multiRows[] = $r;
                    }
                } else {
                    // timestamp window fallback (no turn_id on older messages)
                    if (abs(strtotime($r->created_at) - $multiTs) <= 2) {
                        $multiRows[] = $r;
                    }
                }
            }

            $intents = collect($multiRows)
                ->map(fn($r) => json_decode($r->meta ?? '{}', true)['intent'] ?? null)
                ->filter()->unique()->values()->toArray();

            if (!empty($intents)) {
                // Intersect with the registry so bank_transaction and other
                // primary agents are never treated as setup intents.
                $setupIntents = array_values(array_intersect($intents, AgentRegistry::setupIntents()));

                // ── FIX 2 (original): content-based completion check ──────────
                if (!empty($setupIntents) && in_array('invoice', $intents)) {
                    // Was the invoice already created in this multi-agent turn?
                    // Scan all :invoice-scoped rows (covers old {base}:invoice
                    // and new per-session {base}:{uuid}:invoice scopes).
                    $invoiceAlreadyCreated = false;
                    foreach ($rows as $r) {
                        if (!str_ends_with($r->conversation_id, ':invoice')) continue;
                        $m = json_decode($r->meta ?? '{}', true);
                        if (isset($m['invoice_number'])
                            && $r->created_at >= $lastMultiMessage->created_at
                        ) {
                            $invoiceAlreadyCreated = true;
                            break;
                        }
                    }

                    if ($invoiceAlreadyCreated) {
                        // Fix B — only short-circuit to ['invoice'] when ALL setup agents
                        // are done. If any setup agent is still waiting (e.g. collecting
                        // a rate for a second item), include the full multi-intent group
                        // so the pending setup agent runs again this turn.
                        $setupDoneForContinuation = true;
                        foreach ($setupIntents as $s) {
                            if (!$this->isSetupIntentComplete($s, $conversationId, $byConvo, $lastMultiMessage->created_at, $turnGroupId)) {
                                $setupDoneForContinuation = false;
                                break;
                            }
                        }

                        if ($setupDoneForContinuation) {
                            $this->loadActiveInvoiceNumber($conversationId);
                            return ['intents' => ['invoice'], 'turnGroupId' => $turnGroupId];
                        }
                        // Setup still pending — fall through to return full multi-intent group
                    }

                    $allSetupDone = true;

                    foreach ($setupIntents as $setupIntent) {
                        if (!$this->isSetupIntentComplete($setupIntent, $conversationId, $byConvo, $lastMultiMessage->created_at, $turnGroupId)) {
                            $allSetupDone = false;
                            break;
                        }
                    }

                    if ($allSetupDone) {
                        $this->loadActiveInvoiceNumber($conversationId);
                        return ['intents' => ['invoice'], 'turnGroupId' => $turnGroupId];
                    }
                }

                // ── Secondary/primary pruning ──────────────────────────────────
                // Primary = any intent that is NOT a setup intent.
                // Derived from the registry so adding a new agent requires no edit here.
                $registrySetup    = AgentRegistry::setupIntents();
                $primaryIntents   = array_values(array_diff($intents, $registrySetup));
                $secondaryIntents = array_values(array_intersect($intents, $registrySetup));

                if (!empty($secondaryIntents)) {
                    $remainingSetup = [];

                    foreach ($secondaryIntents as $setupIntent) {
                        if (!$this->isSetupIntentComplete($setupIntent, $conversationId, $byConvo, $lastMultiMessage->created_at, $turnGroupId)) {
                            $remainingSetup[] = $setupIntent;
                        }
                    }

                    if (empty($remainingSetup)) {
                        $survivors = array_values(array_intersect($intents, $primaryIntents));

                        if (!empty($survivors)) {
                            Log::info('[ChatOrchestrator] Setup intents complete — primary only', [
                                'conversation_id' => $conversationId,
                                'dropped'         => array_values($secondaryIntents),
                                'remaining'       => $survivors,
                            ]);
                            if (in_array('invoice', $survivors)) {
                                $this->loadActiveInvoiceNumber($conversationId);
                            }
                            return ['intents' => $survivors, 'turnGroupId' => $turnGroupId];
                        }
                    }
                }

                Log::info('[ChatOrchestrator] Reusing previous multi-intent group from DB', [
                    'conversation_id' => $conversationId,
                    'turn_id'         => $turnId,
                    'intents'         => $intents,
                ]);
                return ['intents' => $intents, 'turnGroupId' => $turnGroupId];
            }
        }

        // ── Fall back to most recent single-intent row ────────────────────────
        foreach ($rows as $r) {
            $m = json_decode($r->meta ?? '{}', true);
            if (isset($m['intent'])) {
                return ['intents' => [$m['intent']], 'turnGroupId' => null];
            }
        }

        return ['intents' => [], 'turnGroupId' => null];
    }

    /**
     * Returns true when $setupIntent has emitted its completion marker in the
     * scoped conversation since $sinceTimestamp (i.e. within the current
     * multi-agent turn). Scoping prevents completion markers from earlier
     * invoice sessions from being mistaken for current-turn completions.
     */
    private function isSetupIntentComplete(
        string  $setupIntent,
        string  $conversationId,
        array   $byConvo,
        string  $sinceTimestamp,
        ?string $turnGroupId    = null,
    ): bool {
        $completionMarker = match ($setupIntent) {
            'client'    => '[CLIENT_ID:',
            'inventory' => '[INVENTORY_ITEM_ID:',
            'narration' => '[NARRATION_HEAD_ID:',
            default     => '✅',
        };

        $intentConvoId = ($turnGroupId !== null)
            ? "{$conversationId}:{$turnGroupId}:{$setupIntent}"
            : "{$conversationId}:{$setupIntent}";

        // When turnGroupId is set, intentConvoId is already session-scoped
        // (UUID-per-session), so timestamp filtering is both redundant and
        // incorrect: setup agents (inventory, client) run in Phase 1 and write
        // their messages BEFORE primary agents (invoice) write theirs. Using
        // lastMultiMessage->created_at (the invoice message, latest in the turn)
        // as sinceTimestamp excludes all setup-phase messages from the same
        // turn, causing isSetupIntentComplete to always return false.
        // Only apply the timestamp filter for legacy unscoped conversations
        // (turnGroupId === null) where session isolation depends on it.
        $sessionRows = ($turnGroupId !== null)
            ? ($byConvo[$intentConvoId] ?? [])
            : array_filter(
                $byConvo[$intentConvoId] ?? [],
                fn($r) => $r->created_at >= $sinceTimestamp
            );

        if (empty($sessionRows)) {
            return false;
        }

        // Fix A — if the agent's most recent message ends with a question mark,
        // the agent is still waiting for user input even if it already emitted
        // a completion marker (e.g. [INVENTORY_ITEM_ID:N] for item 1 but still
        // asking for the rate of item 2). Treat as incomplete.
        // $byConvo is stored DESC so the first element is the latest message.
        $latestRow     = reset($sessionRows);
        $latestContent = $latestRow->content ?? '';
        if (preg_match('/\?\s*$/m', rtrim($latestContent))) {
            return false;
        }

        foreach ($sessionRows as $r) {
            if (str_contains($r->content ?? '', $completionMarker)) {
                return true;
            }
        }

        return false;
    }

    private function loadActiveInvoiceNumber(string $conversationId): void
    {
        // Search both old {base}:invoice (pre-v12) and new per-session
        // {base}:{uuid}:invoice (v12+) scoped conversations.
        $metaJson = DB::table('agent_conversation_messages')
            ->where(function ($q) use ($conversationId) {
                $q->where('conversation_id', $conversationId . ':invoice')
                  ->orWhere('conversation_id', 'like', $conversationId . ':%:invoice');
            })
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.invoice_number') IS NOT NULL")
            ->orderByDesc('created_at')
            ->value('meta');

        if ($metaJson) {
            $decoded   = json_decode($metaJson, true);
            $candidate = $decoded['invoice_number'] ?? null;

            if ($candidate) {
                if ($this->isInvoiceDraft($candidate)) {
                    $this->activeInvoiceNumber = $candidate;
                    Log::info('[ChatOrchestrator] Active invoice number loaded from meta', [
                        'conversation_id' => $conversationId,
                        'invoice_number'  => $this->activeInvoiceNumber,
                    ]);
                    return;
                }

                // Invoice exists in history but is no longer a draft (sent / void / cancelled).
                // Do NOT inject the ACTIVE INVOICE hint — the next invoice request is new.
                Log::info('[ChatOrchestrator] Invoice from meta is no longer a draft — skipping hint', [
                    'conversation_id' => $conversationId,
                    'invoice_number'  => $candidate,
                ]);
                return;
            }
        }

        $lastMultiAt = DB::table('agent_conversation_messages')
            ->where('conversation_id', 'like', $conversationId . ':%')
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.multi_intent') = true")
            ->orderByDesc('created_at')
            ->value('created_at');

        if ($lastMultiAt === null) return;

        $content = DB::table('agent_conversation_messages')
            ->where(function ($q) use ($conversationId) {
                $q->where('conversation_id', $conversationId . ':invoice')
                  ->orWhere('conversation_id', 'like', $conversationId . ':%:invoice');
            })
            ->where('role', 'assistant')
            ->where('created_at', '>=', $lastMultiAt)
            ->orderByDesc('created_at')
            ->value('content');

        if ($content && preg_match('/INV-\d{8}-\d+/', $content, $matches)) {
            $candidate = $matches[0];

            if ($this->isInvoiceDraft($candidate)) {
                $this->activeInvoiceNumber = $candidate;
                Log::info('[ChatOrchestrator] Active invoice number loaded from content fallback', [
                    'conversation_id' => $conversationId,
                    'invoice_number'  => $this->activeInvoiceNumber,
                ]);
            } else {
                Log::info('[ChatOrchestrator] Invoice from content fallback is no longer a draft — skipping hint', [
                    'conversation_id' => $conversationId,
                    'invoice_number'  => $candidate,
                ]);
            }
        }
    }

    /**
     * Return true only if the given invoice number exists as a draft in the DB.
     *
     * Called by loadActiveInvoiceNumber() before injecting the ACTIVE INVOICE hint.
     * A finalized (sent/cancelled/void) invoice must not be surfaced as "active" —
     * doing so forces InvoiceAgent to call get_active_drafts() which returns nothing,
     * causing it to stall instead of creating a new invoice.
     *
     * Invoice numbers are globally unique (INV-YYYYMMDD-NNNNN with uniqueness check),
     * so filtering by invoice_number alone is safe without requiring company_id.
     */
    private function isInvoiceDraft(string $invoiceNumber): bool
    {
        return \App\Models\Invoice::where('invoice_number', $invoiceNumber)
            ->where('status', 'draft')
            ->exists();
    }
}
