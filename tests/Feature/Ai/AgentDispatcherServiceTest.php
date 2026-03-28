<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\ClientAgent;
use App\Ai\Agents\InventoryAgent;
use App\Ai\Agents\InvoiceAgent;
use App\Ai\Agents\NarrationAgent;
use App\Ai\Agents\BankTransactionAgent;
use App\Ai\Services\AgentContextBlackboard;
use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\ObservabilityService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AgentDispatcherService tests.
 *
 * Uses the Laravel AI SDK's Agent::fake() to intercept prompts without
 * calling OpenAI. The observability service is mocked to avoid DB writes
 * in the agent_metrics table during tests.
 */
class AgentDispatcherServiceTest extends TestCase
{
    use RefreshDatabase;

    private AgentDispatcherService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $observability = Mockery::mock(ObservabilityService::class)->shouldIgnoreMissing();

        $this->service = new AgentDispatcherService($observability);
    }

    // ── dispatch() single agent ────────────────────────────────────────────────

    public function test_dispatch_single_invoice_agent(): void
    {
        InvoiceAgent::fake(['INV-20260101-1 created for Infosys Ltd.']);

        $result = $this->service->dispatch(
            intent:         'invoice',
            user:           $this->user,
            message:        'create invoice for Infosys',
            conversationId: null,
        );

        $this->assertArrayHasKey('reply', $result);
        $this->assertArrayHasKey('_raw_reply', $result);
        $this->assertArrayHasKey('conversation_id', $result);
        $this->assertArrayHasKey('_outcome', $result);
        $this->assertStringContainsString('INV-20260101-1', $result['reply']);
    }

    public function test_dispatch_passes_message_to_agent(): void
    {
        InvoiceAgent::fake(['Invoice created.']);

        $this->service->dispatch(
            intent:         'invoice',
            user:           $this->user,
            message:        'create invoice for Acme Corp with 5 chairs at ₹500',
            conversationId: null,
        );

        InvoiceAgent::assertPrompted(fn($prompt) =>
        $prompt->contains('create invoice for Acme Corp')
        );
    }

    public function test_dispatch_injects_hitl_block_when_confirmed(): void
    {
        InvoiceAgent::fake(['Deleted.']);

        $this->service->dispatch(
            intent:         'invoice',
            user:           $this->user,
            message:        'delete invoice INV-001',
            conversationId: null,
            hitlConfirmed:  true,
        );

        InvoiceAgent::assertPrompted(fn($prompt) =>
        $prompt->contains('HITL PRE-AUTHORIZED')
        );
    }

    public function test_dispatch_injects_active_invoice_hint(): void
    {
        InvoiceAgent::fake(['Item added.']);

        $this->service->dispatch(
            intent:              'invoice',
            user:                $this->user,
            message:             'add 5 chairs',
            conversationId:      null,
            activeInvoiceNumber: 'INV-20260101-1',
        );

        InvoiceAgent::assertPrompted(fn($prompt) =>
        $prompt->contains('ACTIVE INVOICE: INV-20260101-1')
        );
    }

    public function test_dispatch_injects_blackboard_preamble_for_invoice(): void
    {
        InvoiceAgent::fake(['Invoice created.']);

        $bb = new AgentContextBlackboard();
        $bb->record('client', '✅ Infosys Ltd created. [CLIENT_ID:14]');
        $bb->setMeta('client_id', 14);

        $this->service->dispatch(
            intent:         'invoice',
            user:           $this->user,
            message:        'invoice them',
            conversationId: null,
            blackboard:     $bb,
            multiIntent:    true,
        );

        InvoiceAgent::assertPrompted(fn($prompt) =>
            $prompt->contains('PRIOR AGENT CONTEXT') &&
            $prompt->contains('client_id = 14')
        );
    }

    public function test_dispatch_result_has_outcome_signal(): void
    {
        ClientAgent::fake(['✅ Client created. [CLIENT_ID:42]']);

        $result = $this->service->dispatch(
            intent:         'client',
            user:           $this->user,
            message:        'add client Acme Corp, email acme@example.com',
            conversationId: null,
        );

        // Outcome must be present (Fix from audit bug #1)
        $this->assertArrayHasKey('_outcome', $result);
        $this->assertNotNull($result['_outcome']);
    }

    public function test_dispatch_error_returns_error_shape(): void
    {
        ClientAgent::fake(function ($prompt) {
            throw new \RuntimeException('Unexpected error');
        });

        $result = $this->service->dispatch(
            intent:         'client',
            user:           $this->user,
            message:        'add client',
            conversationId: null,
        );

        $this->assertTrue($result['_error'] ?? false);
        $this->assertSame('error', $result['_outcome']);
        $this->assertStringContainsString('encountered an issue', $result['reply']);
    }

    public function test_dispatch_rate_limit_error_returns_rate_limit_message(): void
    {
        $attempts = 0;
        ClientAgent::fake(function ($prompt) use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('rate limit exceeded');
        });

        $result = $this->service->dispatch(
            intent:         'client',
            user:           $this->user,
            message:        'add client',
            conversationId: null,
        );

        $this->assertTrue($result['_error'] ?? false);
        $this->assertStringContainsString('processing a lot right now', $result['reply']);
    }

    // ── dispatchAll() ──────────────────────────────────────────────────────────

    public function test_dispatch_all_returns_responses_and_blackboard(): void
    {
        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $result = $this->service->dispatchAll(
            intents:        ['invoice'],
            user:           $this->user,
            message:        'create invoice',
            conversationId: null,
            turnId:         'turn-uuid',
        );

        $this->assertArrayHasKey('responses', $result);
        $this->assertArrayHasKey('blackboard', $result);
        $this->assertInstanceOf(AgentContextBlackboard::class, $result['blackboard']);
        $this->assertArrayHasKey('invoice', $result['responses']);
    }

    public function test_dispatch_all_strips_structured_tags_from_reply(): void
    {
        ClientAgent::fake(['✅ Client created. [CLIENT_ID:42]']);
        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $result = $this->service->dispatchAll(
            intents:        ['client', 'invoice'],
            user:           $this->user,
            message:        'add new client and invoice them',
            conversationId: null,
            turnId:         'turn-uuid',
        );

        // Structured tag must be stripped from user-facing reply
        $this->assertStringNotContainsString('[CLIENT_ID:', $result['responses']['client']['reply']);
    }

    public function test_dispatch_all_preserves_raw_reply_with_tags(): void
    {
        ClientAgent::fake(['✅ Client created. [CLIENT_ID:42]']);
        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $result = $this->service->dispatchAll(
            intents:        ['client', 'invoice'],
            user:           $this->user,
            message:        'add new client and invoice them',
            conversationId: null,
            turnId:         'turn-uuid',
        );

        // Raw reply must preserve the tag (used by blackboard for ID extraction)
        $this->assertStringContainsString('[CLIENT_ID:42]', $result['responses']['client']['_raw_reply']);
    }

    public function test_dispatch_all_extracts_client_id_into_blackboard(): void
    {
        ClientAgent::fake(['✅ Client created. [CLIENT_ID:42]']);
        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $result = $this->service->dispatchAll(
            intents:        ['client', 'invoice'],
            user:           $this->user,
            message:        'add new client Acme and invoice them',
            conversationId: null,
            turnId:         'turn-uuid',
        );

        $this->assertSame(42, $result['blackboard']->getMeta('client_id'));
    }

    public function test_dispatch_all_extracts_inventory_id_into_blackboard(): void
    {
        InventoryAgent::fake(['✅ Chair added. [INVENTORY_ITEM_ID:7]']);
        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $result = $this->service->dispatchAll(
            intents:        ['inventory', 'invoice'],
            user:           $this->user,
            message:        'add chairs and invoice them',
            conversationId: null,
            turnId:         'turn-uuid',
        );

        $this->assertSame(7, $result['blackboard']->getMeta('inventory_item_id'));
    }

    public function test_dispatch_all_seeds_retry_blackboard_from_prior(): void
    {
        // Simulate a retry pass: priorBlackboard has client context from Pass 1
        $priorBb = new AgentContextBlackboard();
        $priorBb->record('client', '✅ Infosys Ltd created. [CLIENT_ID:14]');
        $priorBb->setMeta('client_id', 14);

        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $result = $this->service->dispatchAll(
            intents:         ['invoice'],
            user:            $this->user,
            message:         'invoice them',
            conversationId:  null,
            turnId:          'turn-uuid',
            priorBlackboard: $priorBb,   // Fix 2: pass prior blackboard
        );

        // The retry blackboard should have been seeded with client context
        $this->assertTrue($result['blackboard']->has('client'));
        $this->assertSame(14, $result['blackboard']->getMeta('client_id'));
    }

    public function test_dispatch_all_invoice_prompted_with_prior_context_in_retry(): void
    {
        $priorBb = new AgentContextBlackboard();
        $priorBb->record('client', '✅ Client created. [CLIENT_ID:14]');
        $priorBb->setMeta('client_id', 14);

        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $this->service->dispatchAll(
            intents:         ['invoice'],
            user:            $this->user,
            message:         'invoice them',
            conversationId:  null,
            turnId:          'turn-uuid',
            priorBlackboard: $priorBb,
        );

        // InvoiceAgent must have received the PRIOR AGENT CONTEXT block with client_id
        InvoiceAgent::assertPrompted(fn($prompt) =>
        $prompt->contains('client_id = 14')
        );
    }

    // ── Multi-intent prompt isolation ─────────────────────────────────────────

    public function test_each_agent_only_told_about_its_own_domain(): void
    {
        ClientAgent::fake(['✅ Client created. [CLIENT_ID:42]']);
        InvoiceAgent::fake(['INV-20260101-1 created.']);

        $this->service->dispatchAll(
            intents:        ['client', 'invoice'],
            user:           $this->user,
            message:        'add new client and invoice them',
            conversationId: null,
            turnId:         'turn-uuid',
//            multiIntent:    true,
        );

        ClientAgent::assertPrompted(fn($prompt) =>
        $prompt->contains('ONLY responsible for the "client" domain')
        );

        InvoiceAgent::assertPrompted(fn($prompt) =>
        $prompt->contains('ONLY responsible for the "invoice" domain')
        );
    }

    // ── Narration ID extraction ────────────────────────────────────────────────

    public function test_narration_head_id_extracted_into_blackboard(): void
    {
        NarrationAgent::fake(['✅ Head created. [NARRATION_HEAD_ID:5][NARRATION_SUB_HEAD_ID:12]']);
        BankTransactionAgent::fake(['Transaction narrated.']);

        $result = $this->service->dispatchAll(
            intents:        ['narration', 'bank_transaction'],
            user:           $this->user,
            message:        'create head Sales and narrate this transaction',
            conversationId: null,
            turnId:         'turn-uuid',
        );

        $this->assertSame(5, $result['blackboard']->getMeta('narration_head_id'));
        $this->assertSame(12, $result['blackboard']->getMeta('narration_sub_head_id'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
