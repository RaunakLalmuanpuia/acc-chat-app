<?php

namespace Tests\Feature\Ai;

use App\Ai\ChatOrchestrator;
use App\Ai\Services\AgentContextBlackboard;
use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\EvaluatorService;
use App\Ai\Services\HitlService;
use App\Ai\Services\IntentRouterService;
use App\Ai\Services\ObservabilityService;
use App\Ai\Services\ResponseMergerService;
use App\Ai\Services\ScopeGuardService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * ChatOrchestrator integration tests.
 *
 * Strategy:
 *   - Real: ScopeGuardService, ResponseMergerService, EvaluatorService (pure logic)
 *   - Mocked: AgentDispatcherService (controls LLM calls), IntentRouterService,
 *             HitlService, ObservabilityService
 *
 * DB: RefreshDatabase with agent_conversation_messages seeded for
 * getLastIntents() path tests.
 */
class ChatOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    // Mocks
    private AgentDispatcherService $dispatcher;
    private IntentRouterService    $routerService;
    private HitlService            $hitl;
    private ObservabilityService   $observability;

    // Real services
    private ResponseMergerService  $merger;
    private EvaluatorService       $evaluator;
    private ScopeGuardService      $scopeGuard;

    private ChatOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->dispatcher    = Mockery::mock(AgentDispatcherService::class);
        $this->routerService = Mockery::mock(IntentRouterService::class);
        $this->hitl          = Mockery::mock(HitlService::class);
        $this->observability = Mockery::mock(ObservabilityService::class)->shouldIgnoreMissing();

        $this->merger    = new ResponseMergerService();
        $this->evaluator = new EvaluatorService();
        $this->scopeGuard = new ScopeGuardService();

        $this->orchestrator = new ChatOrchestrator(
            router:       $this->routerService,
            dispatcher:   $this->dispatcher,
            merger:       $this->merger,
            hitl:         $this->hitl,
            observability: $this->observability,
            scopeGuard:   $this->scopeGuard,
            evaluator:    $this->evaluator,
        );
    }

    // ── Scope guard ────────────────────────────────────────────────────────────

    public function test_scope_guard_blocks_out_of_scope_message(): void
    {
        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'write me a poem',
            conversationId: null,
        );

        $this->assertFalse($result['hitl_pending']);
        $this->assertNull($result['pending_id']);      // Fix 12: key always present
        $this->assertNull($result['plan']);
        $this->assertStringContainsString('accounting assistant', $result['reply']);
    }

    public function test_scope_guard_returns_consistent_shape(): void
    {
        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'tell me a joke',
            conversationId: null,
        );

        // All five keys must be present (Fix 12)
        $this->assertArrayHasKey('reply', $result);
        $this->assertArrayHasKey('conversation_id', $result);
        $this->assertArrayHasKey('hitl_pending', $result);
        $this->assertArrayHasKey('pending_id', $result);
        $this->assertArrayHasKey('plan', $result);
    }

    // ── Unknown intents ────────────────────────────────────────────────────────

    public function test_unknown_intent_returns_help_message(): void
    {
        $this->routerService->shouldReceive('resolve')->once()->andReturn([]);
        // No DB rows → getLastIntents returns []
        // No need to stub dispatcher — it should never be called

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'hello',
            conversationId: null,
        );

        $this->assertStringContainsString('accounting assistant', $result['reply']);
        $this->assertFalse($result['hitl_pending']);
        $this->assertNull($result['pending_id']);
    }

    // ── HITL checkpoint ────────────────────────────────────────────────────────

    public function test_hitl_checkpoint_triggered_for_destructive_message(): void
    {
        $this->routerService->shouldReceive('resolve')->once()->andReturn(['client']);
        $this->hitl->shouldReceive('requiresCheckpoint')->once()->andReturn(true);
        $this->hitl->shouldReceive('storePendingAction')
            ->once()
            ->andReturn('pending-uuid-1234');
        $this->hitl->shouldReceive('buildCheckpointMessage')
            ->once()
            ->andReturn('⚠️ Are you sure you want to delete this client?');

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'delete client Infosys',
            conversationId: 'conv-uuid',
        );

        $this->assertTrue($result['hitl_pending']);
        $this->assertSame('pending-uuid-1234', $result['pending_id']);
        $this->assertStringContainsString('Are you sure', $result['reply']);
        $this->assertNull($result['plan']);
    }

    // ── Single-intent dispatch ─────────────────────────────────────────────────

    public function test_single_intent_dispatched_and_reply_returned(): void
    {
        $this->routerService->shouldReceive('resolve')->andReturn(['invoice']);
        $this->hitl->shouldReceive('requiresCheckpoint')->andReturn(false);

        $this->dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->andReturn([
                'responses' => [
                    'invoice' => [
                        'reply'           => 'INV-20260101-1 created.',
                        '_raw_reply'      => 'INV-20260101-1 created.',
                        'conversation_id' => 'conv-uuid:invoice',
                        '_outcome'        => 'completed',
                    ],
                ],
                'blackboard' => new AgentContextBlackboard(),
            ]);

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'create invoice for Infosys',
            conversationId: null,
        );

        $this->assertStringContainsString('INV-20260101-1', $result['reply']);
        $this->assertFalse($result['hitl_pending']);
        $this->assertNull($result['plan']);
    }

    // ── Multi-intent plan ──────────────────────────────────────────────────────

    public function test_multi_intent_turn_includes_plan_summary(): void
    {
        $this->routerService->shouldReceive('resolve')->andReturn(['client', 'invoice']);
        $this->hitl->shouldReceive('requiresCheckpoint')->andReturn(false);

        $this->dispatcher->shouldReceive('dispatchAll')
            ->andReturn([
                'responses' => [
                    'client'  => [
                        'reply' => '[CLIENT_ID:42]', '_raw_reply' => '[CLIENT_ID:42]',
                        'conversation_id' => 'c:client', '_outcome' => 'completed',
                    ],
                    'invoice' => [
                        'reply' => 'INV-20260101-1 created.', '_raw_reply' => 'INV-20260101-1 created.',
                        'conversation_id' => 'c:invoice', '_outcome' => 'completed',
                    ],
                ],
                'blackboard' => new AgentContextBlackboard(),
            ]);

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'add new client XYZ and invoice them',
            conversationId: null,
        );

        $this->assertNotNull($result['plan']);
        $this->assertStringContainsString("I'll", $result['plan']);
        $this->assertStringContainsString('client', $result['plan']);
        $this->assertStringContainsString('invoice', $result['plan']);
    }

    // ── Evaluator retry (multi-intent) ─────────────────────────────────────────

    public function test_evaluator_triggers_retry_for_incomplete_intent(): void
    {
        $this->routerService->shouldReceive('resolve')->andReturn(['client', 'invoice']);
        $this->hitl->shouldReceive('requiresCheckpoint')->andReturn(false);

        $pass1Blackboard = new AgentContextBlackboard();
        $pass1Blackboard->record('client', '[CLIENT_ID:42]');
        $pass1Blackboard->setMeta('client_id', 42);

        // Pass 1: client complete, invoice asks a question
        $this->dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->with(Mockery::on(fn($args) => !isset($args['priorBlackboard']) || $args['priorBlackboard'] === null), Mockery::any())
            ->andReturn([
                'responses' => [
                    'client'  => [
                        'reply' => '[CLIENT_ID:42]', '_raw_reply' => '[CLIENT_ID:42]',
                        'conversation_id' => 'c:client', '_outcome' => 'completed',
                    ],
                    'invoice' => [
                        'reply' => 'What date?', '_raw_reply' => 'What date?',
                        'conversation_id' => 'c:invoice', '_outcome' => 'clarifying',
                    ],
                ],
                'blackboard' => $pass1Blackboard,
            ]);

        // Pass 2 (retry): invoice completes
        $this->dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->andReturn([
                'responses' => [
                    'invoice' => [
                        'reply' => 'INV-20260101-1 created.', '_raw_reply' => 'INV-20260101-1 created.',
                        'conversation_id' => 'c:invoice', '_outcome' => 'completed',
                    ],
                ],
                'blackboard' => new AgentContextBlackboard(),
            ]);

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'add new client XYZ and invoice them',
            conversationId: null,
        );

        $this->assertStringContainsString('INV-20260101-1', $result['reply']);
    }

    public function test_evaluator_does_not_run_for_single_intent(): void
    {
        $this->routerService->shouldReceive('resolve')->andReturn(['invoice']);
        $this->hitl->shouldReceive('requiresCheckpoint')->andReturn(false);

        // dispatchAll called exactly once — no retry even if invoice asks a question
        $this->dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->andReturn([
                'responses' => [
                    'invoice' => [
                        'reply' => 'What date?', '_raw_reply' => 'What date?',
                        'conversation_id' => 'c:invoice', '_outcome' => 'clarifying',
                    ],
                ],
                'blackboard' => new AgentContextBlackboard(),
            ]);

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'create invoice',
            conversationId: null,
        );

        $this->assertSame('What date?', $result['reply']);
    }

    // ── Empty reply fallback ───────────────────────────────────────────────────

    public function test_empty_reply_gets_fallback_message(): void
    {
        $this->routerService->shouldReceive('resolve')->andReturn(['client']);
        $this->hitl->shouldReceive('requiresCheckpoint')->andReturn(false);

        $this->dispatcher->shouldReceive('dispatchAll')->andReturn([
            'responses' => [
                'client' => [
                    'reply' => 'HANDOFF', '_raw_reply' => 'HANDOFF',
                    'conversation_id' => null, '_outcome' => 'completed',
                ],
            ],
            'blackboard' => new AgentContextBlackboard(),
        ]);

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'yes proceed',
            conversationId: null,
        );

        $this->assertNotEmpty($result['reply']);
        $this->assertStringContainsString("ready to continue", $result['reply']);
    }

    // ── confirm() ──────────────────────────────────────────────────────────────

    public function test_confirm_expired_action_returns_expiry_message(): void
    {
        $this->hitl->shouldReceive('consumePendingAction')->once()->andReturn(null);

        $result = $this->orchestrator->confirm(
            user:      $this->user,
            pendingId: 'expired-uuid',
        );

        $this->assertStringContainsString('expired', $result['reply']);
        $this->assertFalse($result['hitl_pending']);
    }

    public function test_confirm_ownership_mismatch_returns_unauthorized(): void
    {
        $this->hitl->shouldReceive('consumePendingAction')->once()->andReturn([
            'user_id'         => 'different-user-id',
            'message'         => 'delete client',
            'intents'         => ['client'],
            'conversation_id' => null,
        ]);

        $result = $this->orchestrator->confirm(
            user:      $this->user,
            pendingId: 'some-uuid',
        );

        $this->assertStringContainsString('not authorized', $result['reply']);
    }

    public function test_confirm_redispatches_on_valid_ownership(): void
    {
        $this->hitl->shouldReceive('consumePendingAction')->once()->andReturn([
            'user_id'         => (string) $this->user->id,
            'message'         => 'delete client Infosys',
            'intents'         => ['client'],
            'conversation_id' => null,
        ]);

        $this->dispatcher->shouldReceive('dispatchAll')->once()->andReturn([
            'responses' => [
                'client' => [
                    'reply' => 'Client deleted.', '_raw_reply' => 'Client deleted.',
                    'conversation_id' => null, '_outcome' => 'completed',
                ],
            ],
            'blackboard' => new AgentContextBlackboard(),
        ]);

        $result = $this->orchestrator->confirm(
            user:      $this->user,
            pendingId: 'valid-uuid',
        );

        $this->assertStringContainsString('deleted', $result['reply']);
        $this->assertFalse($result['hitl_pending']);
        $this->assertNull($result['plan']);
    }

    // ── DB fallback: getLastIntents (Fix 9) ───────────────────────────────────

    public function test_db_fallback_reuses_previous_invoice_intent(): void
    {
        $convId = 'test-conv-' . uniqid();

        // Seed a single invoice message in the scoped conversation
        DB::table('agent_conversation_messages')->insert([
            'conversation_id' => $convId . ':invoice',
            'role'            => 'assistant',
            'content'         => 'INV-20260101-1 created.',
            'meta'            => json_encode(['intent' => 'invoice', 'multi_intent' => false]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Router returns empty → falls back to DB
        $this->routerService->shouldReceive('resolve')->andReturn([]);
        $this->hitl->shouldReceive('requiresCheckpoint')->andReturn(false);

        $this->dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->with(Mockery::on(fn($args) => in_array('invoice', $args['intents'] ?? [])), Mockery::any())
            ->andReturn([
                'responses' => [
                    'invoice' => [
                        'reply' => 'Added item.', '_raw_reply' => 'Added item.',
                        'conversation_id' => $convId . ':invoice', '_outcome' => 'completed',
                    ],
                ],
                'blackboard' => new AgentContextBlackboard(),
            ]);

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'yes',
            conversationId: $convId,
        );

        $this->assertStringContainsString('Added item.', $result['reply']);
    }

    public function test_db_fallback_returns_unknown_when_no_history(): void
    {
        $this->routerService->shouldReceive('resolve')->andReturn([]);
        // No DB rows for this conversation → returns []

        $result = $this->orchestrator->handle(
            user:           $this->user,
            message:        'yes',
            conversationId: 'conv-with-no-history',
        );

        $this->assertStringContainsString('accounting assistant', $result['reply']);
    }

    // ── Fix 1: active invoice number loaded for router-resolved invoice turns ──

    public function test_active_invoice_number_loaded_for_router_resolved_invoice_turn(): void
    {
        $convId = 'conv-invoice-' . uniqid();

        // Seed an invoice message with invoice_number in meta
        DB::table('agent_conversation_messages')->insert([
            'conversation_id' => $convId . ':invoice',
            'role'            => 'assistant',
            'content'         => 'INV-20260101-1 created.',
            'meta'            => json_encode([
                'intent'         => 'invoice',
                'invoice_number' => 'INV-20260101-1',
                'multi_intent'   => false,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->routerService->shouldReceive('resolve')->andReturn(['invoice']);
        $this->hitl->shouldReceive('requiresCheckpoint')->andReturn(false);

        // Capture what was passed to dispatchAll
        $capturedArgs = null;
        $this->dispatcher->shouldReceive('dispatchAll')
            ->once()
            ->andReturnUsing(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args[0];
                return [
                    'responses' => [
                        'invoice' => [
                            'reply' => 'Line item added.', '_raw_reply' => 'Line item added.',
                            'conversation_id' => $convId . ':invoice', '_outcome' => 'completed',
                        ],
                    ],
                    'blackboard' => new AgentContextBlackboard(),
                ];
            });

        $this->orchestrator->handle(
            user:           $this->user,
            message:        'add another item',
            conversationId: $convId,
        );

        // Fix 1: activeInvoiceNumber must have been loaded and forwarded
        $this->assertSame('INV-20260101-1', $capturedArgs['activeInvoiceNumber'] ?? null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
