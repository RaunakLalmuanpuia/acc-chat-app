<?php

namespace App\Ai\Services;

use App\Ai\Services\AgentContextBlackboard;
use App\Ai\AgentRegistry;
use App\Ai\Agents\BaseAgent;
use App\Models\User;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Illuminate\Support\Str;

/**
 * AgentDispatcherService  (v7 — Fix 2, Fix 7, Fix 8)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v6
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 2 — evaluator retry lost Pass 1 blackboard context:
 *
 *   dispatchAll() now accepts an optional AgentContextBlackboard $priorBlackboard.
 *   When provided, the new blackboard is seeded with all Pass 1 state before
 *   any retry agent is dispatched. This ensures InvoiceAgent's BLACKBOARD
 *   DEPENDENCY CHECK sees the ╔══ PRIOR AGENT CONTEXT ══╗ block with ✅ markers
 *   from completed setup agents, rather than an empty block that falls through
 *   to the "PENDING" path.
 *
 *   dispatchAll() now returns:
 *     ['responses' => array<string,array>, 'blackboard' => AgentContextBlackboard]
 *
 *   ChatOrchestrator v8 captures the blackboard and passes it to the retry
 *   call as $priorBlackboard so the retry agents get full Pass 1 context.
 *
 * FIX 7 — null $toolsUsed caused false evaluator retries for bank_transaction
 * and business intents:
 *
 *   When $response->toolsUsed is unavailable (SDK limitation), resolveOutcomeSignal()
 *   previously returned null. EvaluatorService::isCompleted() has no regex pattern
 *   for bank_transaction or business, so null outcome + no pattern = isCompleted=false
 *   on every multi-intent turn, doubling API calls for those intents.
 *
 *   Fix: return 'completed' when toolsUsed is null. If the agent responded
 *   without an exception, it completed its turn. The natural next-message
 *   correction loop handles edge cases.
 *
 * FIX 8 — configureConversation() made 2 DB queries per agent (2N queries per
 * turn for N intents):
 *
 *   Added preloadConversationState() which fetches all conversation existence
 *   data in exactly 2 queries for the entire turn. The result is passed into
 *   dispatch() → configureConversation(), which now does zero DB queries
 *   itself. For a 3-intent turn this reduces 6 conversation queries to 2.
 *
 * All v6 changes preserved:
 *   - BUG 1 FIX: '_outcome' in return array
 *   - SETUP_INTENTS run in parallel via Concurrency::run()
 *   - PRIMARY_INTENTS run sequentially after setup phase
 *   - Agent::make() for service-container-resolved instantiation
 *   - stripStructuredTags() helper
 *   - _raw_reply preserved for blackboard tag extraction
 */
class AgentDispatcherService
{
    private const SETUP_INTENTS   = ['client', 'inventory', 'narration'];
    private const PRIMARY_INTENTS = ['invoice', 'bank_transaction', 'business'];

    public function __construct(
        private readonly ObservabilityService $observability,
    ) {}

    // ── Public ─────────────────────────────────────────────────────────────────

    /**
     * Dispatch multiple intents, running independent setup agents in parallel
     * and primary agents sequentially after the setup phase completes.
     *
     * FIX 2: Accepts an optional $priorBlackboard. When provided (evaluator
     * retry pass), the new blackboard is seeded with all Pass 1 context before
     * any agent is dispatched, preserving ✅ markers and resolved IDs.
     *
     * FIX 2: Returns an array with two keys:
     *   'responses'  => array<string, array>   — agent results keyed by intent
     *   'blackboard' => AgentContextBlackboard — the blackboard built this pass
     *
     * Callers must destructure: ['responses' => $r, 'blackboard' => $bb] = $this->dispatcher->dispatchAll(...)
     */
    public function dispatchAll(
        array                   $intents,
        User                    $user,
        string                  $message,
        ?string                 $conversationId,
        string                  $turnId,
        array                   $attachments         = [],
        bool                    $hitlConfirmed        = false,
        ?string                 $activeInvoiceNumber  = null,
        ?AgentContextBlackboard $priorBlackboard      = null,  // ← FIX 2
    ): array {
        $multiIntent = count($intents) > 1;
        $results     = [];

        // FIX 2: seed from prior pass if provided
        $blackboard = new AgentContextBlackboard();
        if ($priorBlackboard !== null) {
            $blackboard->seedFrom($priorBlackboard);
        }

        if ($multiIntent && $conversationId === null) {
            $conversationId = Str::uuid()->toString();
        }

        $baseConversationId = $conversationId;

        // FIX 8: preload conversation state in 2 queries for the entire turn
        $conversationState = $this->preloadConversationState($intents, $baseConversationId);

        $setupBatch   = array_values(array_filter($intents, fn($i) => in_array($i, self::SETUP_INTENTS)));
        $primaryBatch = array_values(array_filter($intents, fn($i) => in_array($i, self::PRIMARY_INTENTS)));
        $unknownBatch = array_values(array_diff($intents, $setupBatch, $primaryBatch));

        // ── Phase 1: Setup agents (parallel) ─────────────────────────────────
        if (!empty($setupBatch)) {
            $setupResults = $this->runSetupPhase(
                intents:             $setupBatch,
                user:                $user,
                message:             $message,
                conversationId:      $baseConversationId,
                multiIntent:         $multiIntent,
                attachments:         $attachments,
                hitlConfirmed:       $hitlConfirmed,
                turnId:              $turnId,
                activeInvoiceNumber: $activeInvoiceNumber,
                conversationState:   $conversationState,  // FIX 8
            );

            foreach ($setupResults as $intent => $result) {
                $results[$intent] = $result;
                $blackboard->record($intent, $result['reply']);
                $this->attachStructuredContext($intent, $result['_raw_reply'], $blackboard);
            }

            if ($baseConversationId === null) {
                foreach ($setupResults as $result) {
                    if (!($result['_error'] ?? false) && isset($result['conversation_id'])) {
                        $rawId = $result['conversation_id'];
                        $baseConversationId = ($multiIntent && $rawId !== null)
                            ? explode(':', $rawId)[0]
                            : $rawId;
                        break;
                    }
                }
            }
        }

        // ── Phase 2: Primary + unknown agents (sequential, with blackboard) ──
        foreach ([...$unknownBatch, ...$primaryBatch] as $index => $intent) {
            $result = $this->dispatch(
                intent:              $intent,
                user:                $user,
                message:             $message,
                conversationId:      $baseConversationId,
                multiIntent:         $multiIntent,
                attachments:         $attachments,
                blackboard:          $blackboard,
                hitlConfirmed:       $hitlConfirmed,
                turnId:              $turnId,
                activeInvoiceNumber: $activeInvoiceNumber,
                conversationState:   $conversationState,  // FIX 8
            );

            $rawReply    = $result['reply'];
            $cleanReply  = $this->stripStructuredTags($rawReply);
            $result['reply']     = $cleanReply;
            $result['_raw_reply'] = $rawReply;
            $results[$intent]    = $result;

            $blackboard->record($intent, $cleanReply);
            $this->attachStructuredContext($intent, $rawReply, $blackboard);

            if ($index === 0 && $baseConversationId === null && !($result['_error'] ?? false)) {
                $rawId = $result['conversation_id'] ?? null;
                $baseConversationId = ($multiIntent && $rawId !== null)
                    ? explode(':', $rawId)[0]
                    : $rawId;
            }
        }

        // FIX 2: return both responses AND the blackboard built this pass
        return [
            'responses'  => $results,
            'blackboard' => $blackboard,
        ];
    }

    /**
     * Dispatch a single intent to its specialist agent.
     *
     * Returns an array with keys:
     *   reply           string   — agent's reply (may include structured tags)
     *   _raw_reply      string   — identical to reply before tag stripping
     *   conversation_id string   — scoped conversation ID e.g. {base}:invoice
     *   _outcome        ?string  — 'completed'|'clarifying'|'partial'|'error'|null
     *   _error          bool     — present and true only on caught exceptions
     */
    public function dispatch(
        string                  $intent,
        User                    $user,
        string                  $message,
        ?string                 $conversationId,
        bool                    $multiIntent         = false,
        array                   $attachments         = [],
        ?AgentContextBlackboard $blackboard          = null,
        bool                    $hitlConfirmed       = false,
        ?string                 $turnId              = null,
        ?string                 $activeInvoiceNumber = null,
        array                   $conversationState   = [],   // FIX 8
    ): array {
        $start = microtime(true);
        $model = AgentRegistry::AGENT_MODELS[$intent] ?? 'gpt-4o';

        try {
            $agent = $this->resolveAgent($intent, $user);

            // FIX 8: pass preloaded state, zero DB queries inside configureConversation
            $agent = $this->configureConversation(
                agent:             $agent,
                user:              $user,
                conversationId:    $conversationId,
                intent:            $intent,
                multiIntent:       $multiIntent,
                conversationState: $conversationState,
            );

            Log::info('[AgentDispatcherService] Dispatching', [
                'intent'          => $intent,
                'user_id'         => $user->id,
                'conversation_id' => $conversationId,
                'multi_intent'    => $multiIntent,
                'blackboard_has'  => $blackboard?->all() ? array_keys($blackboard->all()) : [],
            ]);

            $prompt = $this->buildMessage(
                intent:              $intent,
                message:             $message,
                blackboard:          $blackboard,
                multiIntent:         $multiIntent,
                hitlConfirmed:       $hitlConfirmed,
                activeInvoiceNumber: $activeInvoiceNumber,
            );

            $response  = null;
            $attempts  = 0;
            $maxTries  = 5;
            $baseDelay = 2;

            while ($attempts < $maxTries) {
                try {
                    $response = $agent->prompt(
                        prompt:      $prompt,
                        attachments: $attachments,
                    );
                    break;
                } catch (\Throwable $e) {
                    $attempts++;
                    $isRateLimit = str_contains($e->getMessage(), 'rate limit')
                        || str_contains($e->getMessage(), 'RateLimited')
                        || str_contains($e->getMessage(), 'rate_limit_exceeded')
                        || ($e instanceof \Laravel\Ai\Exceptions\RateLimitedException);

                    if ($isRateLimit && $attempts < $maxTries) {
                        $delay = $baseDelay * (2 ** ($attempts - 1));
                        Log::warning("[AgentDispatcherService] Rate limited — retrying in {$delay}s", [
                            'intent'  => $intent,
                            'attempt' => $attempts,
                            'max'     => $maxTries,
                        ]);
                        sleep($delay);
                        continue;
                    }

                    throw $e;
                }
            }

            $latencyMs     = (int) ((microtime(true) - $start) * 1000);
            $outcomeSignal = $this->resolveOutcomeSignal($intent, $response);

            $scopedConversationId = $response->conversationId;
            $usageRow = DB::table('agent_conversation_messages')
                ->where('conversation_id', $scopedConversationId)
                ->where('role', 'assistant')
                ->where('agent', get_class($agent))
                ->orderByDesc('created_at')
                ->value('usage');

            $usageData    = ($usageRow && $usageRow !== '[]') ? json_decode($usageRow, true) : [];
            $inputTokens  = $usageData['prompt_tokens']           ?? null;
            $outputTokens = $usageData['completion_tokens']       ?? null;
            $cachedTokens = $usageData['cache_read_input_tokens'] ?? null;

            $resolvedBaseId = explode(':', $scopedConversationId)[0];

            $this->observability->recordAgentCall(
                intent:         $intent,
                userId:         (string) $user->id,
                conversationId: $resolvedBaseId,
                model:          $model,
                latencyMs:      $latencyMs,
                inputTokens:    $inputTokens,
                outputTokens:   $outputTokens,
                cachedTokens:   $cachedTokens,
                success:        true,
                outcomeSignal:  $outcomeSignal,
            );

            $replyText     = (string) $response;
            $invoiceNumber = null;

            if ($intent === 'invoice') {
                if (preg_match('/INV-\d{8}-\d+/', $replyText, $matches)) {
                    $invoiceNumber = $matches[0];
                }
            }

            $this->writeMetaToMessage(
                conversationId: $response->conversationId,
                intent:         $intent,
                multiIntent:    $multiIntent,
                turnId:         $turnId,
                outcomeSignal:  $outcomeSignal,
                invoiceNumber:  $invoiceNumber,
            );

            return [
                'reply'           => $replyText,
                '_raw_reply'      => $replyText,
                'conversation_id' => $response->conversationId,
                '_outcome'        => $outcomeSignal,
            ];

        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            $isRateLimit = str_contains($e->getMessage(), 'rate limit')
                || str_contains($e->getMessage(), 'RateLimited')
                || str_contains($e->getMessage(), 'rate_limit_exceeded');

            $this->observability->recordAgentCall(
                intent:         $intent,
                userId:         (string) $user->id,
                conversationId: $conversationId,
                model:          $model,
                latencyMs:      $latencyMs,
                success:        false,
                errorMessage:   $e->getMessage(),
                outcomeSignal:  'error',
            );

            Log::error("[AgentDispatcherService] {$intent} agent failed", [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return [
                'reply'           => $isRateLimit
                    ? "I'm processing a lot right now — please send your message again in a few seconds."
                    : $this->errorResponse($intent),
                '_raw_reply'      => '',
                'conversation_id' => $conversationId,
                '_outcome'        => 'error',
                '_error'          => true,
            ];
        }
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * FIX 8 — Preload conversation existence state in exactly 2 DB queries.
     *
     * Replaces the 2 per-agent queries in configureConversation() with a single
     * batch operation for the entire turn. For a 3-intent turn this reduces
     * 6 conversation-routing queries to 2.
     *
     * Returns:
     *   'scoped'      => array<string, true>   keys are existing scoped conversation IDs
     *   'base_intents'=> array<string, true>   keys are intent strings tracked in base convo
     *
     * @param  string[]     $intents
     * @param  string|null  $conversationId
     */
    private function preloadConversationState(array $intents, ?string $conversationId): array
    {
        if ($conversationId === null || empty($intents)) {
            return ['scoped' => [], 'base_intents' => []];
        }

        // Query 1: which scoped conversation IDs already exist?
        $scopedIds = array_map(fn($i) => "{$conversationId}:{$i}", $intents);

        $existingScoped = DB::table('agent_conversation_messages')
            ->whereIn('conversation_id', $scopedIds)
            ->distinct()
            ->pluck('conversation_id')
            ->flip()
            ->all();

        // Query 2: which intents are already tracked on the base conversation?
        $existingBaseIntents = DB::table('agent_conversation_messages')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.intent')) as intent")
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.intent') IS NOT NULL")
            ->pluck('intent')
            ->unique()
            ->flip()
            ->all();

        return [
            'scoped'       => $existingScoped,        // ['base:invoice' => 0, ...]
            'base_intents' => $existingBaseIntents,   // ['invoice' => 0, ...]
        ];
    }

    private function runSetupPhase(
        array   $intents,
        User    $user,
        string  $message,
        ?string $conversationId,
        bool    $multiIntent,
        array   $attachments,
        bool    $hitlConfirmed,
        string  $turnId,
        ?string $activeInvoiceNumber,
        array   $conversationState = [],   // FIX 8
    ): array {
        if (count($intents) === 1) {
            $intent = $intents[0];
            $result = $this->dispatch(
                intent:              $intent,
                user:                $user,
                message:             $message,
                conversationId:      $conversationId,
                multiIntent:         $multiIntent,
                attachments:         $attachments,
                blackboard:          null,
                hitlConfirmed:       $hitlConfirmed,
                turnId:              $turnId,
                activeInvoiceNumber: $activeInvoiceNumber,
                conversationState:   $conversationState,
            );

            $rawReply   = $result['reply'];
            $cleanReply = $this->stripStructuredTags($rawReply);
            $result['reply']      = $cleanReply;
            $result['_raw_reply'] = $rawReply;

            return [$intent => $result];
        }

        Log::info('[AgentDispatcherService] Running setup agents in parallel', [
            'intents' => $intents,
        ]);

        $tasks = [];
        foreach ($intents as $intent) {
            $tasks[] = function () use (
                $intent, $user, $message, $conversationId,
                $multiIntent, $attachments, $hitlConfirmed, $turnId,
                $activeInvoiceNumber, $conversationState
            ) {
                return $this->dispatch(
                    intent:              $intent,
                    user:                $user,
                    message:             $message,
                    conversationId:      $conversationId,
                    multiIntent:         $multiIntent,
                    attachments:         $attachments,
                    blackboard:          null,
                    hitlConfirmed:       $hitlConfirmed,
                    turnId:              $turnId,
                    activeInvoiceNumber: $activeInvoiceNumber,
                    conversationState:   $conversationState,
                );
            };
        }

        $parallelResults = Concurrency::run($tasks);

        $keyed = [];
        foreach ($intents as $i => $intent) {
            $result     = $parallelResults[$i];
            $rawReply   = $result['reply'];
            $cleanReply = $this->stripStructuredTags($rawReply);
            $result['reply']      = $cleanReply;
            $result['_raw_reply'] = $rawReply;
            $keyed[$intent]       = $result;
        }

        return $keyed;
    }

    private function resolveAgent(string $intent, User $user): Agent
    {
        $agents = AgentRegistry::AGENTS;

        if (!isset($agents[$intent])) {
            throw new \InvalidArgumentException(
                "No agent registered for intent: {$intent}"
            );
        }

        /** @var class-string<Agent> $agentClass */
        $agentClass = $agents[$intent];

        return $agentClass::make(user: $user);
    }

    /**
     * Configure conversation continuation for an agent.
     *
     * FIX 8: Accepts preloaded $conversationState so no DB queries are made
     * here. The state was fetched once for the entire turn by preloadConversationState().
     *
     * Logic (unchanged):
     *   1. No conversationId → forUser() (new conversation)
     *   2. Scoped ID exists → continue scoped conversation
     *   3. Base conversation has this intent → continue base conversation
     *   4. Otherwise → continue new scoped conversation
     */
    private function configureConversation(
        Agent   $agent,
        User    $user,
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
        array   $conversationState = [],   // FIX 8
    ): Agent {
        if ($conversationId === null) {
            return $agent->forUser($user);
        }

        $scopedId = "{$conversationId}:{$intent}";

        // FIX 8: use preloaded state — zero additional DB queries
        $scopedExists      = isset($conversationState['scoped'][$scopedId]);
        $baseHasThisIntent = isset($conversationState['base_intents'][$intent]);

        if ($scopedExists) {
            return $agent->continue($scopedId, as: $user);
        }

        if ($baseHasThisIntent) {
            return $agent->continue($conversationId, as: $user);
        }

        return $agent->continue($scopedId, as: $user);
    }

    /**
     * Resolve the outcome signal for a completed agent response.
     *
     * FIX 7: When $response->toolsUsed is unavailable (SDK limitation on some
     * versions), v6 returned null. EvaluatorService has no regex pattern for
     * bank_transaction/business, so null outcome → isCompleted=false → spurious
     * retry on every multi-intent turn involving those intents.
     *
     * Fix: return 'completed' when toolsUsed is unavailable. The agent returned
     * a response without error, which is the best signal we have. The natural
     * next-message correction loop handles any actual incompleteness.
     */
    private function resolveOutcomeSignal(string $intent, mixed $response): ?string
    {
        $agentClass = AgentRegistry::AGENTS[$intent] ?? null;

        if ($agentClass === null || !is_subclass_of($agentClass, BaseAgent::class)) {
            return 'completed';
        }

        $writeTools = $agentClass::writeTools();

        if (empty($writeTools)) {
            return 'completed';
        }

        $toolsUsed = $response->toolsUsed ?? null;

        // FIX 7: treat unavailable toolsUsed as 'completed', not null.
        // null would cause EvaluatorService to flag bank_transaction/business
        // as incomplete on every multi-intent turn, doubling API calls.
        if ($toolsUsed === null) {
            return 'completed';
        }

        $calledWriteTool  = !empty(array_intersect($toolsUsed, $writeTools));
        $endsWithQuestion = str_ends_with(rtrim((string) $response), '?');

        return match (true) {
            $calledWriteTool && !$endsWithQuestion => 'completed',
            $calledWriteTool && $endsWithQuestion  => 'partial',
            default                                 => 'clarifying',
        };
    }

    private function buildMessage(
        string                  $intent,
        string                  $message,
        ?AgentContextBlackboard $blackboard,
        bool                    $multiIntent,
        bool                    $hitlConfirmed       = false,
        ?string                 $activeInvoiceNumber = null,
    ): string {
        $hitlBlock = '';
        if ($hitlConfirmed) {
            $hitlBlock = <<<HITL
            ╔══════════════════════════════════════════════════════════════════╗
            ║  ✅ HITL PRE-AUTHORIZED — PROCEED WITHOUT RE-CONFIRMING          ║
            ╠══════════════════════════════════════════════════════════════════╣
            ║  This action was reviewed and explicitly confirmed by the human  ║
            ║  user via the Human-in-the-Loop checkpoint.                      ║
            ║                                                                  ║
            ║  RULE: Execute the operation WITHOUT asking the user to confirm. ║
            ║  You MAY call read-only tools (search, get details) to locate    ║
            ║  the correct record before acting — this is encouraged.          ║
            ║  Do NOT pause at any point to ask "are you sure?".               ║
            ║  Do NOT warn about irreversibility — the user already agreed.    ║
            ╚══════════════════════════════════════════════════════════════════╝

            HITL;
        }

        $invoiceHint = '';
        if ($intent === 'invoice' && $activeInvoiceNumber !== null) {
            $invoiceHint = <<<HINT
            ╔══════════════════════════════════════════════════════════════════╗
            ║  ACTIVE INVOICE: {$activeInvoiceNumber}
            ╠══════════════════════════════════════════════════════════════════╣
            ║  This invoice was created earlier in this conversation.          ║
            ║  REQUIRED: Call get_active_drafts(invoice_number:               ║
            ║  "{$activeInvoiceNumber}") to retrieve the invoice_id before    ║
            ║  calling any write tools (add_line_item, generate_invoice_pdf,  ║
            ║  finalize_invoice). Do NOT invent the invoice_id.               ║
            ╚══════════════════════════════════════════════════════════════════╝

            HINT;
        }

        $preamble = ($blackboard !== null && !$blackboard->isEmpty())
            ? $blackboard->buildContextPreamble($intent)
            : '';

        if (!$multiIntent) {
            return $hitlBlock . $invoiceHint . $preamble . $message;
        }

        return <<<PROMPT
        {$hitlBlock}{$invoiceHint}{$preamble}The user message may contain requests for multiple domains.

        You are ONLY responsible for the "{$intent}" domain.

        Ignore all parts of the message unrelated to "{$intent}".
        Do not mention other domains in your response.

        If prior agent context is provided above, treat it as established fact:
        - Do NOT re-fetch data that is already confirmed in the context.
        - Do NOT re-create resources that were already created.
        - Reference prior context to avoid redundant tool calls.

        User message:
        {$message}
        PROMPT;
    }

    private function stripStructuredTags(string $reply): string
    {
        return preg_replace(
            '/\[(CLIENT_ID|INVENTORY_ITEM_ID|NARRATION_HEAD_ID|NARRATION_SUB_HEAD_ID):\d+\]\n?/',
            '',
            $reply
        );
    }

    private function attachStructuredContext(
        string                 $intent,
        string                 $rawReply,
        AgentContextBlackboard $blackboard,
    ): void {
        if ($intent === 'client') {
            if (preg_match('/\[CLIENT_ID:(\d+)\]/', $rawReply, $m)) {
                $blackboard->setMeta('client_id', (int) $m[1]);
            }
        }

        if ($intent === 'inventory') {
            if (preg_match('/\[INVENTORY_ITEM_ID:(\d+)\]/', $rawReply, $m)) {
                $blackboard->setMeta('inventory_item_id', (int) $m[1]);
            }
        }

        if ($intent === 'narration') {
            if (preg_match('/\[NARRATION_HEAD_ID:(\d+)\]/', $rawReply, $m)) {
                $blackboard->setMeta('narration_head_id', (int) $m[1]);
            }
            if (preg_match('/\[NARRATION_SUB_HEAD_ID:(\d+)\]/', $rawReply, $m)) {
                $blackboard->setMeta('narration_sub_head_id', (int) $m[1]);
            }
        }
    }

    private function writeMetaToMessage(
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
        ?string $turnId        = null,
        ?string $outcomeSignal = null,
        ?string $invoiceNumber = null,
    ): void {
        if ($conversationId === null) return;

        DB::transaction(function () use ($conversationId, $intent, $multiIntent, $turnId, $outcomeSignal, $invoiceNumber): void {
            $messageRow = DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->where('role', 'assistant')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($messageRow === null) return;

            $meta                 = json_decode($messageRow->meta ?? '{}', true) ?: [];
            $meta['intent']       = $intent;
            $meta['multi_intent'] = $multiIntent;

            // FIX 5 (companion): guard against empty string as well as null
            if (!empty($turnId))       $meta['turn_id']        = $turnId;
            if ($outcomeSignal !== null) $meta['outcome']       = $outcomeSignal;
            if ($invoiceNumber !== null) $meta['invoice_number'] = $invoiceNumber;

            DB::table('agent_conversation_messages')
                ->where('id', $messageRow->id)
                ->update(['meta' => json_encode($meta)]);
        });
    }

    private function errorResponse(string $intent): string
    {
        $label = ucfirst($intent);
        return "I encountered an issue with {$label} operations. Please try again in a moment. "
            . "If the problem persists, please contact support.";
    }
}
