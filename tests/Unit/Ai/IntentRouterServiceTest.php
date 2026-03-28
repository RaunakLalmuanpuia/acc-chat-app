<?php

namespace Tests\Unit\Ai;

use App\Ai\Agents\RouterAgent;
use App\Ai\Services\IntentRouterService;
use Tests\TestCase;

class IntentRouterServiceTest extends TestCase
{
    private IntentRouterService $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = $this->app->make(IntentRouterService::class);
    }

    // ── Follow-up fast-path ────────────────────────────────────────────────────

    /** @dataProvider followUpMessagesProvider */
    public function test_follow_up_messages_return_empty_without_router_call(string $message): void
    {
        RouterAgent::fake()->preventStrayPrompts();
        // preventStrayPrompts ensures router is never called for follow-ups
        // (would throw if it were)

        $result = $this->router->resolve($message, 'conv-uuid');

        $this->assertSame([], $result);
        RouterAgent::assertNeverPrompted();
    }

    public static function followUpMessagesProvider(): array
    {
        return [
            'yes'             => ['yes'],
            'proceed'         => ['proceed'],
            'ok'              => ['ok'],
            'go ahead'        => ['go ahead'],
            'short confirm'   => ['sure'],
            'rate answer'     => ['7640876052, test@example.com, 200'],
            'field value'     => ['email is test@example.com'],
            'date answer'     => ['invoice date is 2026-01-01'],
        ];
    }

    // ── Single-intent resolution ───────────────────────────────────────────────

    public function test_resolves_single_invoice_intent(): void
    {
        RouterAgent::fake(['{"intents": ["invoice"]}']);

        $result = $this->router->resolve('create an invoice for Infosys', null);

        $this->assertSame(['invoice'], $result);
    }

    public function test_resolves_single_client_intent(): void
    {
        RouterAgent::fake(['{"intents": ["client"]}']);

        $result = $this->router->resolve('add a new client called Acme', null);

        $this->assertSame(['client'], $result);
    }

    public function test_resolves_bank_transaction_intent(): void
    {
        RouterAgent::fake(['{"intents": ["bank_transaction"]}']);

        $result = $this->router->resolve('show me my transactions', null);

        $this->assertSame(['bank_transaction'], $result);
    }

    public function test_unknown_intent_returns_empty(): void
    {
        RouterAgent::fake(['{"intents": ["unknown"]}']);

        $result = $this->router->resolve('hello there', null);

        $this->assertSame([], $result);
    }

    // ── Multi-intent resolution ────────────────────────────────────────────────

    public function test_resolves_client_and_invoice_multi_intent(): void
    {
        // Voting path: 3 calls, all return the same result → majority
        RouterAgent::fake([
            '{"intents": ["client", "invoice"]}',
            '{"intents": ["client", "invoice"]}',
            '{"intents": ["client", "invoice"]}',
        ]);

        $result = $this->router->resolve(
            'add new client XYZ and create an invoice for them',
            null
        );

        $this->assertContains('client', $result);
        $this->assertContains('invoice', $result);
    }

    public function test_resolves_three_way_multi_intent(): void
    {
        RouterAgent::fake([
            '{"intents": ["client", "inventory", "invoice"]}',
            '{"intents": ["client", "inventory", "invoice"]}',
            '{"intents": ["client", "inventory", "invoice"]}',
        ]);

        $result = $this->router->resolve(
            'create invoice for new client XYZ, they want 30 chairs',
            null
        );

        $this->assertContains('client', $result);
        $this->assertContains('inventory', $result);
        $this->assertContains('invoice', $result);
    }

    // ── Majority voting ────────────────────────────────────────────────────────

    public function test_majority_vote_used_when_first_result_is_ambiguous(): void
    {
        // Vote 1 (first call): client + invoice
        // Vote 2 + 3 (parallel): both return just invoice
        // Majority: invoice wins (2/3), client loses (1/3)
        RouterAgent::fake([
            '{"intents": ["client", "invoice"]}', // vote 1
            '{"intents": ["invoice"]}',            // vote 2
            '{"intents": ["invoice"]}',            // vote 3
        ]);

        $result = $this->router->resolve(
            'invoice existing client Infosys',  // ambiguous — existing or new?
            null
        );

        $this->assertContains('invoice', $result);
        $this->assertNotContains('client', $result);
    }

    public function test_falls_back_to_first_vote_when_no_majority(): void
    {
        // All three votes disagree completely
        RouterAgent::fake([
            '{"intents": ["client"]}',
            '{"intents": ["invoice"]}',
            '{"intents": ["inventory"]}',
        ]);

        $result = $this->router->resolve('ambiguous message about something', null);

        // Falls back to first vote
        $this->assertSame(['client'], $result);
    }

    // ── JSON parsing ──────────────────────────────────────────────────────────

    public function test_handles_json_wrapped_in_markdown_code_block(): void
    {
        RouterAgent::fake(['```json{"intents": ["invoice"]}```']);

        $result = $this->router->resolve('create invoice', null);

        $this->assertSame(['invoice'], $result);
    }

    public function test_handles_invalid_json_gracefully(): void
    {
        RouterAgent::fake(['not valid json at all']);

        $result = $this->router->resolve('create invoice', null);

        $this->assertSame([], $result);
    }

    public function test_handles_missing_intents_key_gracefully(): void
    {
        RouterAgent::fake(['{"result": ["invoice"]}']);

        $result = $this->router->resolve('create invoice', null);

        $this->assertSame([], $result);
    }

    // ── Invalid intents filtered ───────────────────────────────────────────────

    public function test_unknown_intent_values_are_filtered(): void
    {
        RouterAgent::fake(['{"intents": ["invoice", "unknown", "nonexistent_domain"]}']);

        $result = $this->router->resolve('create invoice', null);

        // 'unknown' and 'nonexistent_domain' should be filtered
        $this->assertSame(['invoice'], $result);
    }

    public function test_duplicate_intents_are_deduplicated(): void
    {
        RouterAgent::fake(['{"intents": ["invoice", "invoice", "client"]}']);

        $result = $this->router->resolve('create invoice', null);

        $this->assertCount(2, $result);
        $this->assertContains('invoice', $result);
        $this->assertContains('client', $result);
    }

    // ── Router agent is prompted ───────────────────────────────────────────────

    public function test_router_agent_is_actually_prompted(): void
    {
        RouterAgent::fake(['{"intents": ["invoice"]}']);

        $this->router->resolve('create an invoice', null);

        RouterAgent::assertPrompted('create an invoice');
    }

    public function test_single_unambiguous_intent_prompts_router_once(): void
    {
        RouterAgent::fake(['{"intents": ["business"]}']);

        $this->router->resolve('show my business profile', null);

        // Non-ambiguous single intent → no voting → router called exactly once
        RouterAgent::assertPrompted(function ($prompt) {
            return $prompt->contains('business profile');
        });
    }
}
