<?php

namespace App\Ai;

use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\EvaluatorService;
use App\Ai\Services\HitlService;
use App\Ai\Services\IntentRouterService;
use App\Ai\Services\ObservabilityService;
use App\Ai\Services\ResponseMergerService;
use App\Ai\Services\ScopeGuardService;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ChatOrchestrator  (v8 — Fix 1, Fix 2, Fix 5, Fix 9, Fix 12, Fix 16)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v7
 * ─────────────────────────────────────────────────────────────────────────────
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

        $guardResult = $this->scopeGuard->evaluate($message, (string) $user->id);

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

        $intents = $this->router->resolve($message, $conversationId);

        Log::info('[ChatOrchestrator] Resolved intents', ['intents' => $intents]);

        if (empty($intents) && $conversationId !== null) {
            $lastIntents = $this->getLastIntents($conversationId);

            if (!empty($lastIntents)) {
                Log::info('[ChatOrchestrator] Reusing previous intents from DB', [
                    'conversation_id' => $conversationId,
                    'intents'         => $lastIntents,
                ]);
                $intents = $lastIntents;
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
            user:           $user,
            message:        $message,
            conversationId: $conversationId,
            intents:        $intents,
            attachments:    $attachments,
            turnStart:      $turnStart,
            turnId:         $turnId,
            plan:           $plan,
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
        ?string $turnId        = null,   // FIX 5: was string = ''
        bool    $hitlConfirmed = false,
        ?string $plan          = null,
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
    private function getLastIntents(string $conversationId): array
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
            return [];
        }

        // Index by conversation_id for O(1) scoped lookups in PHP
        $byConvo = [];
        foreach ($rows as $row) {
            $byConvo[$row->conversation_id][] = $row;
        }

        $invoiceConvoId = $conversationId . ':invoice';

        // ── Derive state (previously individual queries) ──────────────────────

        // lastInvoiceMessage: latest assistant message from :invoice scope
        // that has an invoice_number in meta (proved the invoice was created)
        $lastInvoiceMessage = null;
        foreach ($byConvo[$invoiceConvoId] ?? [] as $r) {
            $m = json_decode($r->meta ?? '{}', true);
            if (isset($m['invoice_number'])) {
                $lastInvoiceMessage = $r;
                break; // rows are already ordered desc
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

        // ── FIX 4: compute skip flag once ─────────────────────────────────────
        $skipToSingleIntent = false;

        if ($lastInvoiceMessage !== null) {
            // Is there any non-invoice scoped message newer than the invoice message?
            foreach ($rows as $r) {
                if ($r->conversation_id === $invoiceConvoId) continue;
                if ($r->conversation_id === $conversationId) continue;

                $m = json_decode($r->meta ?? '{}', true);
                if (empty($m['intent'])) continue;

                if ($r->created_at > $lastInvoiceMessage->created_at) {
                    $skipToSingleIntent = true;
                    Log::info('[ChatOrchestrator] Non-invoice context more recent — skipping to single-intent fallback', [
                        'conversation_id' => $conversationId,
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
            return ['invoice'];
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
                $setupIntents = array_diff($intents, ['invoice']);

                // ── FIX 2 (original): content-based completion check ──────────
                if (!empty($setupIntents) && in_array('invoice', $intents)) {
                    // Was the invoice already created in this multi-agent turn?
                    $invoiceAlreadyCreated = false;
                    foreach ($byConvo[$invoiceConvoId] ?? [] as $r) {
                        $m = json_decode($r->meta ?? '{}', true);
                        if (isset($m['invoice_number'])
                            && $r->created_at >= $lastMultiMessage->created_at
                        ) {
                            $invoiceAlreadyCreated = true;
                            break;
                        }
                    }

                    if ($invoiceAlreadyCreated) {
                        $this->loadActiveInvoiceNumber($conversationId);
                        return ['invoice'];
                    }

                    $allSetupDone = true;

                    foreach ($setupIntents as $setupIntent) {
                        $completionMarker = match ($setupIntent) {
                            'client'    => '[CLIENT_ID:',
                            'inventory' => '[INVENTORY_ITEM_ID:',
                            'narration' => '[NARRATION_HEAD_ID:',
                            default     => '✅',
                        };

                        $intentConvoId = $conversationId . ':' . $setupIntent;
                        $isDone        = false;

                        foreach ($byConvo[$intentConvoId] ?? [] as $r) {
                            if ($r->created_at >= $lastMultiMessage->created_at
                                && str_contains($r->content ?? '', $completionMarker)
                            ) {
                                $isDone = true;
                                break;
                            }
                        }

                        if (!$isDone) {
                            $allSetupDone = false;
                            break;
                        }
                    }

                    if ($allSetupDone) {
                        $this->loadActiveInvoiceNumber($conversationId);
                        return ['invoice'];
                    }
                }

                // ── Secondary/primary pruning ──────────────────────────────────
                $primaryIntents   = ['bank_transaction', 'invoice'];
                $secondaryIntents = array_diff($intents, $primaryIntents);

                if (!empty($secondaryIntents)) {
                    $remainingSetup = [];

                    foreach ($secondaryIntents as $setupIntent) {
                        $completionMarker = match ($setupIntent) {
                            'client'    => '[CLIENT_ID:',
                            'inventory' => '[INVENTORY_ITEM_ID:',
                            'narration' => '[NARRATION_HEAD_ID:',
                            default     => '✅',
                        };

                        $intentConvoId = $conversationId . ':' . $setupIntent;
                        $isDone        = false;

                        // Check across all messages (not just since lastMultiMessage)
                        foreach ($byConvo[$intentConvoId] ?? [] as $r) {
                            if (str_contains($r->content ?? '', $completionMarker)) {
                                $isDone = true;
                                break;
                            }
                        }

                        if (!$isDone) {
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
                            return $survivors;
                        }
                    }
                }

                Log::info('[ChatOrchestrator] Reusing previous multi-intent group from DB', [
                    'conversation_id' => $conversationId,
                    'turn_id'         => $turnId,
                    'intents'         => $intents,
                ]);
                return $intents;
            }
        }

        // ── Fall back to most recent single-intent row ────────────────────────
        foreach ($rows as $r) {
            $m = json_decode($r->meta ?? '{}', true);
            if (isset($m['intent'])) {
                return [$m['intent']];
            }
        }

        return [];
    }

    private function loadActiveInvoiceNumber(string $conversationId): void
    {
        $metaJson = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId . ':invoice')
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.invoice_number') IS NOT NULL")
            ->orderByDesc('created_at')
            ->value('meta');

        if ($metaJson) {
            $decoded = json_decode($metaJson, true);
            $this->activeInvoiceNumber = $decoded['invoice_number'] ?? null;

            if ($this->activeInvoiceNumber) {
                Log::info('[ChatOrchestrator] Active invoice number loaded from meta', [
                    'conversation_id' => $conversationId,
                    'invoice_number'  => $this->activeInvoiceNumber,
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
            ->where('conversation_id', $conversationId . ':invoice')
            ->where('role', 'assistant')
            ->where('created_at', '>=', $lastMultiAt)
            ->orderByDesc('created_at')
            ->value('content');

        if ($content && preg_match('/INV-\d{8}-\d+/', $content, $matches)) {
            $this->activeInvoiceNumber = $matches[0];

            Log::info('[ChatOrchestrator] Active invoice number loaded from content fallback', [
                'conversation_id' => $conversationId,
                'invoice_number'  => $this->activeInvoiceNumber,
            ]);
        }
    }
}
