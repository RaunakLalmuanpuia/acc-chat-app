<?php

namespace App\Http\Controllers;

use App\Ai\ChatOrchestrator;
use App\Ai\Services\AttachmentBuilderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AiChatController  (v3 — Gap 5 fix: plan key forwarded to frontend)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v2
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * GAP 5 FIX — 'plan' key silently dropped before reaching frontend:
 *
 *   ChatOrchestrator v6/v7 returns 'plan' => string|null in its response
 *   array for multi-intent turns. The plan is a deterministic, zero-latency
 *   summary like "I'll look up or create the client, then create and configure
 *   the invoice." — intended for display in the frontend before the full reply
 *   arrives (Anthropic transparency principle).
 *
 *   v2 of this controller only forwarded known keys to the 'chatResponse'
 *   flash payload, omitting 'plan' entirely. The frontend never received it.
 *
 *   Fix: add 'plan' => $result['plan'] ?? null to the chatResponse array in
 *   both send() and confirm(). confirm() always returns plan=null (single
 *   confirmed action, not a new planning step) but the key is included for
 *   shape consistency so the frontend does not need a null-guard.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FRONTEND chatResponse SHAPE (v3)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Single-intent or unknown:
 *    { reply, conversation_id, hitl_pending: false, plan: null }
 *
 *  Multi-intent (new):
 *    { reply, conversation_id, hitl_pending: false,
 *      plan: "I'll look up or create the client, then create and configure the invoice." }
 *
 *  HITL triggered:
 *    { reply (warning), conversation_id, hitl_pending: true, pending_id, plan: null }
 *
 *  HITL confirmed:
 *    { reply, conversation_id, hitl_pending: false, plan: null }
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FRONTEND USAGE GUIDANCE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  When plan is non-null, display it as a brief "Here's what I'll do…"
 *  header above the reply. It renders immediately (it's part of the same
 *  response payload), giving the user context for a reply that covers
 *  multiple agents' work.
 *
 *  Example rendering:
 *    if (chatResponse.plan) {
 *      <p class="text-sm text-muted">{chatResponse.plan}</p>
 *    }
 *    <div>{chatResponse.reply}</div>
 */
class AiChatController extends Controller
{
    public function __construct(
        private readonly ChatOrchestrator         $orchestrator,
        private readonly AttachmentBuilderService $attachmentBuilder,
    ) {}

    /**
     * Render the Accounting Chat page.
     */
    public function index(): Response
    {
        return Inertia::render('Accounting/Chat', [
            'conversationId' => null,
        ]);
    }

    /**
     * Handle a chat message.
     *
     * Form fields:
     *   message          string       (required)
     *   conversation_id  string|null  UUID of an existing conversation
     *   attachments[]    file[]       (optional)
     *
     * Flashes to chatResponse shared prop:
     *   reply           string
     *   conversation_id string|null
     *   hitl_pending    bool
     *   pending_id      string|null  (only when hitl_pending = true)
     *   plan            string|null  (multi-intent planning summary, or null)
     */
    public function send(Request $request): RedirectResponse
    {
        set_time_limit(120);
        $request->validate([
            'message'         => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'attachments'     => ['nullable', 'array', 'max:5'],
            'attachments.*'   => [
                'file',
                'max:20480',
                'mimes:pdf,csv,xlsx,xls,docx,doc,txt,png,jpg,jpeg,webp',
            ],
        ]);

        $user           = $request->user();
        $message        = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $attachments    = $this->attachmentBuilder->fromRequest($request);

        try {
            $result = $this->orchestrator->handle(
                user:           $user,
                message:        $message,
                conversationId: $conversationId,
                attachments:    $attachments,
            );

            return back()->with('chatResponse', [
                'reply'           => $result['reply'],
                'conversation_id' => $result['conversation_id'],
                'hitl_pending'    => $result['hitl_pending'],
                'pending_id'      => $result['pending_id'] ?? null,
                'plan'            => $result['plan'] ?? null,  // ← GAP 5 FIX
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChatController] Unhandled orchestrator error', [
                'user_id' => $user->id,
                'message' => $message,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return back()->withErrors([
                'ai' => 'The assistant encountered an error. Please try again in a moment.',
            ]);
        }
    }

    /**
     * Confirm a HITL-pending destructive action.
     *
     * Form fields:
     *   pending_id     string  (required, UUID)
     *   attachments[]  file[]  (optional)
     *
     * Flashes to chatResponse shared prop:
     *   reply           string
     *   conversation_id string|null
     *   hitl_pending    bool   (always false — confirmation resolves the checkpoint)
     *   pending_id      null   (cleared)
     *   plan            null   (confirmations are single actions, no multi-intent plan)
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'pending_id'      => ['required', 'string', 'uuid'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'attachments'     => ['nullable', 'array', 'max:5'],
            'attachments.*'   => [
                'file',
                'max:20480',
                'mimes:pdf,csv,xlsx,xls,docx,doc,txt,png,jpg,jpeg,webp',
            ],
        ]);

        $user        = $request->user();
        $pendingId   = $request->input('pending_id');
        $attachments = $this->attachmentBuilder->fromRequest($request);

        try {
            $result = $this->orchestrator->confirm(
                user:        $user,
                pendingId:   $pendingId,
                attachments: $attachments,
            );

            return back()->with('chatResponse', [
                'reply'           => $result['reply'],
                'conversation_id' => $result['conversation_id'],
                'hitl_pending'    => false,
                'pending_id'      => null,
                'plan'            => null,  // ← GAP 5 FIX (always null for confirmations)
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChatController] HITL confirm error', [
                'user_id'    => $user->id,
                'pending_id' => $pendingId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return back()->withErrors([
                'ai' => 'The confirmation could not be processed. Please try again.',
            ]);
        }
    }
}
