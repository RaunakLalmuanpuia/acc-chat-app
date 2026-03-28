<?php

namespace Tests\Unit\Ai;

use App\Ai\Services\HitlService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HitlServiceTest extends TestCase
{
    private HitlService $hitl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hitl = new HitlService();
    }

    // ── requiresCheckpoint ─────────────────────────────────────────────────────

    /** @dataProvider checkpointRequiredProvider */
    public function test_checkpoint_required_for_destructive_messages(
        string $message,
        array  $intents,
    ): void {
        $this->assertTrue(
            $this->hitl->requiresCheckpoint($message, $intents),
            "Expected checkpoint for: '{$message}'"
        );
    }

    public static function checkpointRequiredProvider(): array
    {
        return [
            'delete client'       => ['delete the client Infosys', ['client']],
            'remove client'       => ['remove client Acme', ['client']],
            'delete invoice'      => ['delete invoice INV-20260101-1', ['invoice']],
            'void invoice'        => ['void this invoice', ['invoice']],
            'cancel invoice'      => ['cancel the invoice', ['invoice']],
            'delete inventory'    => ['delete this inventory item', ['inventory']],
            'delete narration'    => ['delete narration head Sales', ['narration']],
            'reconcile tx'        => ['reconcile this transaction', ['bank_transaction']],
            'delete multi-intent' => ['delete the client and their invoices', ['client', 'invoice']],
        ];
    }

    /** @dataProvider checkpointNotRequiredProvider */
    public function test_no_checkpoint_for_safe_messages(
        string $message,
        array  $intents,
    ): void {
        $this->assertFalse(
            $this->hitl->requiresCheckpoint($message, $intents),
            "Expected NO checkpoint for: '{$message}'"
        );
    }

    public static function checkpointNotRequiredProvider(): array
    {
        return [
            'create invoice'      => ['create an invoice', ['invoice']],
            'add client'          => ['add a new client', ['client']],
            'list transactions'   => ['show my transactions', ['bank_transaction']],
            'narrate transaction' => ['narrate this transaction', ['bank_transaction']],
            'update client'       => ['update client email', ['client']],
            'unknown intent'      => ['hello', ['unknown']],
            // Destructive word but non-destructive intent (business has no DESTRUCTIVE capability)
            // This depends on AgentRegistry::destructiveIntents() — adjust if business is DESTRUCTIVE
        ];
    }

    public function test_no_checkpoint_when_intents_are_empty(): void
    {
        $this->assertFalse($this->hitl->requiresCheckpoint('delete everything', []));
    }

    // ── storePendingAction ─────────────────────────────────────────────────────

    public function test_store_returns_uuid_string(): void
    {
        $pendingId = $this->hitl->storePendingAction(
            userId:         'user-1',
            message:        'delete client Infosys',
            intents:        ['client'],
            conversationId: null,
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $pendingId
        );
    }

    public function test_stored_action_is_retrievable(): void
    {
        $pendingId = $this->hitl->storePendingAction(
            userId:         'user-1',
            message:        'delete client Infosys',
            intents:        ['client'],
            conversationId: 'conv-uuid',
        );

        $action = $this->hitl->retrievePendingAction($pendingId);

        $this->assertNotNull($action);
        $this->assertSame('user-1', $action['user_id']);
        $this->assertSame('delete client Infosys', $action['message']);
        $this->assertSame(['client'], $action['intents']);
        $this->assertSame('conv-uuid', $action['conversation_id']);
    }

    public function test_stored_action_preserves_null_conversation_id(): void
    {
        $pendingId = $this->hitl->storePendingAction(
            userId:         'user-1',
            message:        'delete client',
            intents:        ['client'],
            conversationId: null,
        );

        $action = $this->hitl->retrievePendingAction($pendingId);

        $this->assertNull($action['conversation_id']);
    }

    // ── consumePendingAction ───────────────────────────────────────────────────

    public function test_consume_returns_action_and_removes_from_cache(): void
    {
        $pendingId = $this->hitl->storePendingAction(
            userId:  'user-1',
            message: 'delete client',
            intents: ['client'],
            conversationId: null,
        );

        $action = $this->hitl->consumePendingAction($pendingId);

        $this->assertNotNull($action);
        $this->assertSame('user-1', $action['user_id']);

        // Second consume must return null — it was deleted
        $again = $this->hitl->consumePendingAction($pendingId);
        $this->assertNull($again);
    }

    public function test_consume_unknown_id_returns_null(): void
    {
        $result = $this->hitl->consumePendingAction('non-existent-uuid');

        $this->assertNull($result);
    }

    public function test_consume_is_effectively_atomic_via_cache_pull(): void
    {
        // Verify that after consume, retrieve also returns null
        // (both operate on the same cache key — Cache::pull atomicity)
        $pendingId = $this->hitl->storePendingAction(
            userId:  'user-2',
            message: 'void invoice',
            intents: ['invoice'],
            conversationId: null,
        );

        $this->hitl->consumePendingAction($pendingId);

        $this->assertNull($this->hitl->retrievePendingAction($pendingId));
    }

    public function test_two_different_pending_ids_are_independent(): void
    {
        $id1 = $this->hitl->storePendingAction('u1', 'msg1', ['client'],  null);
        $id2 = $this->hitl->storePendingAction('u2', 'msg2', ['invoice'], null);

        $this->hitl->consumePendingAction($id1);

        $this->assertNull($this->hitl->retrievePendingAction($id1));
        $this->assertNotNull($this->hitl->retrievePendingAction($id2));
    }

    // ── buildCheckpointMessage ─────────────────────────────────────────────────

    public function test_checkpoint_message_includes_original_message(): void
    {
        $msg = $this->hitl->buildCheckpointMessage(
            'delete client Infosys',
            ['client']
        );

        $this->assertStringContainsString('delete client Infosys', $msg);
    }

    public function test_checkpoint_message_lists_domain(): void
    {
        $msg = $this->hitl->buildCheckpointMessage('delete', ['client', 'invoice']);

        $this->assertStringContainsString('Client', $msg);
        $this->assertStringContainsString('Invoice', $msg);
    }

    public function test_checkpoint_message_warns_about_irreversibility(): void
    {
        $msg = $this->hitl->buildCheckpointMessage('delete', ['client']);

        $this->assertStringContainsString('cannot be undone', $msg);
    }

    public function test_checkpoint_message_mentions_file_reattachment(): void
    {
        $msg = $this->hitl->buildCheckpointMessage('delete', ['client']);

        $this->assertStringContainsString('re-attach', $msg);
    }

    // ── TTL ────────────────────────────────────────────────────────────────────

    public function test_expired_action_returns_null(): void
    {
        // Manually place a cache entry with an already-expired TTL
        Cache::put('hitl:pending:expired-test', ['user_id' => 'u1'], now()->subSecond());

        $result = $this->hitl->retrievePendingAction('expired-test');

        $this->assertNull($result);
    }
}
