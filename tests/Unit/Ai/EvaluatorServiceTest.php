<?php

namespace Tests\Unit\Ai;

use App\Ai\Services\EvaluatorService;
use Tests\TestCase;

class EvaluatorServiceTest extends TestCase
{
    private EvaluatorService $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new EvaluatorService();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function response(string $reply, ?string $outcome = null, bool $error = false): array
    {
        return [
            'reply'      => $reply,
            '_raw_reply' => $reply,
            '_outcome'   => $outcome,
            '_error'     => $error,
        ];
    }

    // ── Pattern-based completion ───────────────────────────────────────────────

    public function test_client_completed_by_client_id_marker(): void
    {
        $responses = [
            'client' => $this->response('✅ Client created. [CLIENT_ID:42]', 'completed'),
        ];

        $result = $this->evaluator->evaluate($responses, ['client'], 'create client', false);

        $this->assertFalse($result->shouldRetry);
    }

    public function test_client_incomplete_when_no_marker(): void
    {
        $responses = [
            'client' => $this->response('What is the client email?', 'clarifying'),
        ];

        $result = $this->evaluator->evaluate($responses, ['client'], 'create client', false);

        $this->assertTrue($result->shouldRetry);
        $this->assertContains('client', $result->intentsToRetry);
    }

    public function test_inventory_completed_by_inventory_item_id_marker(): void
    {
        $responses = [
            'inventory' => $this->response('✅ Chair added. [INVENTORY_ITEM_ID:7]', 'completed'),
        ];

        $result = $this->evaluator->evaluate($responses, ['inventory'], 'add chair', false);

        $this->assertFalse($result->shouldRetry);
    }

    public function test_narration_completed_by_narration_head_id_marker(): void
    {
        $responses = [
            'narration' => $this->response('✅ Head created. [NARRATION_HEAD_ID:5]', 'completed'),
        ];

        $result = $this->evaluator->evaluate($responses, ['narration'], 'add head', false);

        $this->assertFalse($result->shouldRetry);
    }

    public function test_invoice_completed_by_inv_number_pattern(): void
    {
        $responses = [
            'invoice' => $this->response('Invoice INV-20260101-101 created.', 'completed'),
        ];

        $result = $this->evaluator->evaluate($responses, ['invoice'], 'create invoice', false);

        $this->assertFalse($result->shouldRetry);
    }

    // ── Outcome-signal-based completion (bank_transaction, business) ───────────

    public function test_bank_transaction_completed_by_outcome_signal(): void
    {
        $responses = [
            'bank_transaction' => $this->response('Transactions listed.', 'completed'),
        ];

        $result = $this->evaluator->evaluate($responses, ['bank_transaction'], 'show transactions', false);

        $this->assertFalse($result->shouldRetry);
    }

    public function test_bank_transaction_partial_outcome_treated_as_completed(): void
    {
        $responses = [
            'bank_transaction' => $this->response('Narrated. Is this correct?', 'partial'),
        ];

        $result = $this->evaluator->evaluate($responses, ['bank_transaction'], 'narrate', false);

        $this->assertFalse($result->shouldRetry);
    }

    public function test_bank_transaction_clarifying_triggers_retry(): void
    {
        $responses = [
            'bank_transaction' => $this->response('Which head should I use?', 'clarifying'),
        ];

        $result = $this->evaluator->evaluate($responses, ['bank_transaction'], 'narrate', false);

        $this->assertTrue($result->shouldRetry);
        $this->assertContains('bank_transaction', $result->intentsToRetry);
    }

    // ── Multi-intent evaluation ────────────────────────────────────────────────

    public function test_only_incomplete_intents_queued_for_retry(): void
    {
        $responses = [
            'client'  => $this->response('✅ Client created. [CLIENT_ID:42]', 'completed'),
            'invoice' => $this->response('What date should I use?', 'clarifying'),
        ];

        $result = $this->evaluator->evaluate($responses, ['client', 'invoice'], 'create invoice', false);

        $this->assertTrue($result->shouldRetry);
        $this->assertContains('invoice', $result->intentsToRetry);
        $this->assertNotContains('client', $result->intentsToRetry);
    }

    public function test_all_complete_returns_pass(): void
    {
        $responses = [
            'client'    => $this->response('[CLIENT_ID:42]', 'completed'),
            'inventory' => $this->response('[INVENTORY_ITEM_ID:7]', 'completed'),
            'invoice'   => $this->response('INV-20260101-1 created.', 'completed'),
        ];

        $result = $this->evaluator->evaluate(
            $responses,
            ['client', 'inventory', 'invoice'],
            'create invoice',
            false,
        );

        $this->assertFalse($result->shouldRetry);
        $this->assertEmpty($result->intentsToRetry);
    }

    // ── Error handling ─────────────────────────────────────────────────────────

    public function test_errored_intents_are_not_retried(): void
    {
        $responses = [
            'client' => $this->response('Rate limited', 'error', true),
        ];

        $result = $this->evaluator->evaluate($responses, ['client'], 'create client', false);

        // Errors are handled by AgentDispatcherService rate-limit retry, not by evaluator
        $this->assertFalse($result->shouldRetry);
        $this->assertEmpty($result->intentsToRetry);
    }

    public function test_missing_response_for_intent_treated_as_incomplete_not_retried(): void
    {
        // Response for 'invoice' is missing entirely
        $responses = [
            'client' => $this->response('[CLIENT_ID:42]', 'completed'),
        ];

        $result = $this->evaluator->evaluate(
            $responses,
            ['client', 'invoice'],
            'create invoice',
            false,
        );

        // Missing responses are treated as incomplete but NOT retried (same path as errors)
        $this->assertFalse($result->shouldRetry);
    }

    // ── isRetry guard ──────────────────────────────────────────────────────────

    public function test_is_retry_true_never_triggers_another_retry(): void
    {
        $responses = [
            'invoice' => $this->response('Still asking a question?', 'clarifying'),
        ];

        $result = $this->evaluator->evaluate($responses, ['invoice'], 'create invoice', true);

        $this->assertFalse($result->shouldRetry);
        $this->assertEmpty($result->intentsToRetry);
    }

    // ── Augmentation content ───────────────────────────────────────────────────

    public function test_augmentation_includes_completed_agents_context(): void
    {
        $responses = [
            'client'  => $this->response('✅ Infosys Ltd created. [CLIENT_ID:14]', 'completed'),
            'invoice' => $this->response('What date?', 'clarifying'),
        ];

        $result = $this->evaluator->evaluate($responses, ['client', 'invoice'], 'create invoice', false);

        $this->assertStringContainsString('client agent completed', $result->augmentation);
        $this->assertStringContainsString('✅ Infosys Ltd created.', $result->augmentation);
    }

    public function test_augmentation_excludes_handoff_replies(): void
    {
        $responses = [
            'client'  => $this->response('HANDOFF', 'completed'),
            'invoice' => $this->response('What date?', 'clarifying'),
        ];

        $result = $this->evaluator->evaluate($responses, ['client', 'invoice'], 'create invoice', false);

        $this->assertStringNotContainsString('HANDOFF', $result->augmentation);
    }

    public function test_augmentation_contains_prior_agent_context_sentinel(): void
    {
        // InvoiceAgent's BLACKBOARD DEPENDENCY CHECK looks for this header
        $responses = [
            'client'  => $this->response('[CLIENT_ID:42]', 'completed'),
            'invoice' => $this->response('What date?', 'clarifying'),
        ];

        $result = $this->evaluator->evaluate($responses, ['client', 'invoice'], 'create invoice', false);

        $this->assertStringContainsString('PRIOR AGENT CONTEXT', $result->augmentation);
        $this->assertStringContainsString('EVALUATOR FEEDBACK', $result->augmentation);
    }

    // ── logFinalOutcome ────────────────────────────────────────────────────────

    public function test_log_final_outcome_does_not_throw(): void
    {
        $responses = [
            'client'  => $this->response('[CLIENT_ID:42]', 'completed'),
            'invoice' => $this->response('[INV-20260101-1]', 'completed'),
        ];

        // Should not throw; purely observational
        $this->evaluator->logFinalOutcome($responses, ['client', 'invoice'], 'create invoice');

        $this->assertTrue(true); // reached without exception
    }

    public function test_log_final_outcome_handles_empty_responses_gracefully(): void
    {
        $this->evaluator->logFinalOutcome([], [], 'empty turn');

        $this->assertTrue(true);
    }
}
