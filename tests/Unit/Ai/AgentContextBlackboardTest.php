<?php


namespace Tests\Unit\Ai;

use App\Ai\Services\AgentContextBlackboard;
use Tests\TestCase;

class AgentContextBlackboardTest extends TestCase
{
    // ── isEmpty / has ──────────────────────────────────────────────────────────

    public function test_new_blackboard_is_empty(): void
    {
        $bb = new AgentContextBlackboard();

        $this->assertTrue($bb->isEmpty());
        $this->assertFalse($bb->has('client'));
    }

    public function test_record_marks_intent_as_present(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', '✅ Client Acme Corp created. [CLIENT_ID:42]');

        $this->assertFalse($bb->isEmpty());
        $this->assertTrue($bb->has('client'));
        $this->assertFalse($bb->has('invoice'));
    }

    // ── getReply ───────────────────────────────────────────────────────────────

    public function test_get_reply_returns_recorded_text(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', 'Client found: Infosys Ltd');

        $this->assertSame('Client found: Infosys Ltd', $bb->getReply('client'));
    }

    public function test_get_reply_returns_null_for_missing_intent(): void
    {
        $bb = new AgentContextBlackboard();

        $this->assertNull($bb->getReply('invoice'));
    }

    // ── meta ───────────────────────────────────────────────────────────────────

    public function test_set_and_get_meta(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->setMeta('client_id', 42);

        $this->assertSame(42, $bb->getMeta('client_id'));
    }

    public function test_get_meta_returns_default_when_missing(): void
    {
        $bb = new AgentContextBlackboard();

        $this->assertNull($bb->getMeta('client_id'));
        $this->assertSame(0, $bb->getMeta('client_id', 0));
    }

    public function test_all_meta_exposes_full_map(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->setMeta('client_id', 10);
        $bb->setMeta('inventory_item_id', 20);

        $this->assertSame(['client_id' => 10, 'inventory_item_id' => 20], $bb->allMeta());
    }

    // ── all ────────────────────────────────────────────────────────────────────

    public function test_all_returns_full_state(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', 'Client reply');
        $bb->record('inventory', 'Inventory reply');

        $all = $bb->all();

        $this->assertArrayHasKey('client', $all);
        $this->assertArrayHasKey('inventory', $all);
        $this->assertSame('Client reply', $all['client']['reply']);
    }

    // ── seedFrom ───────────────────────────────────────────────────────────────

    public function test_seed_from_copies_state_and_meta(): void
    {
        $source = new AgentContextBlackboard();
        $source->record('client', '✅ Client created [CLIENT_ID:42]');
        $source->record('inventory', '✅ Chair added [INVENTORY_ITEM_ID:7]');
        $source->setMeta('client_id', 42);
        $source->setMeta('inventory_item_id', 7);

        $target = new AgentContextBlackboard();
        $target->seedFrom($source);

        $this->assertTrue($target->has('client'));
        $this->assertTrue($target->has('inventory'));
        $this->assertSame(42, $target->getMeta('client_id'));
        $this->assertSame(7, $target->getMeta('inventory_item_id'));
    }

    public function test_seed_from_is_additive_preserving_existing_state(): void
    {
        $source = new AgentContextBlackboard();
        $source->record('client', 'Client reply');
        $source->setMeta('client_id', 42);

        $target = new AgentContextBlackboard();
        $target->record('narration', 'Narration reply');
        $target->setMeta('narration_head_id', 99);

        $target->seedFrom($source);

        // Source entries merged in
        $this->assertTrue($target->has('client'));
        // Original entries preserved
        $this->assertTrue($target->has('narration'));
        $this->assertSame(42, $target->getMeta('client_id'));
        $this->assertSame(99, $target->getMeta('narration_head_id'));
    }

    public function test_seed_from_empty_source_leaves_target_unchanged(): void
    {
        $source = new AgentContextBlackboard();

        $target = new AgentContextBlackboard();
        $target->record('client', 'Existing reply');
        $target->seedFrom($source);

        $this->assertTrue($target->has('client'));
        $this->assertCount(1, $target->all());
    }

    // ── buildContextPreamble ───────────────────────────────────────────────────

    public function test_preamble_is_empty_when_blackboard_is_empty(): void
    {
        $bb = new AgentContextBlackboard();

        $this->assertSame('', $bb->buildContextPreamble('invoice'));
    }

    public function test_preamble_is_empty_when_only_own_intent_recorded(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('invoice', 'Some invoice reply');

        // invoice agent asking for its own preamble — no prior context
        $this->assertSame('', $bb->buildContextPreamble('invoice'));
    }

    public function test_preamble_contains_other_intents_replies(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', '✅ Client Infosys Ltd created. [CLIENT_ID:14]');
        $bb->record('inventory', '✅ Chair added. [INVENTORY_ITEM_ID:7]');

        $preamble = $bb->buildContextPreamble('invoice');

        $this->assertStringContainsString('client agent completed', $preamble);
        $this->assertStringContainsString('✅ Client Infosys Ltd created.', $preamble);
        $this->assertStringContainsString('inventory agent completed', $preamble);
        $this->assertStringNotContainsString('invoice agent completed', $preamble);
    }

    public function test_preamble_excludes_the_requesting_intent(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', 'Client reply');
        $bb->record('invoice', 'Invoice reply — should not appear for invoice');

        $preamble = $bb->buildContextPreamble('invoice');

        $this->assertStringContainsString('client agent completed', $preamble);
        $this->assertStringNotContainsString('Invoice reply — should not appear', $preamble);
    }

    public function test_preamble_injects_resolved_client_id_for_invoice(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', '✅ Client created.');
        $bb->setMeta('client_id', 42);

        $preamble = $bb->buildContextPreamble('invoice');

        $this->assertStringContainsString('client_id = 42', $preamble);
        $this->assertStringContainsString('Do NOT call lookup_client', $preamble);
    }

    public function test_preamble_injects_resolved_inventory_id_for_invoice(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('inventory', '✅ Chair added.');
        $bb->setMeta('inventory_item_id', 7);

        $preamble = $bb->buildContextPreamble('invoice');

        $this->assertStringContainsString('inventory_item_id = 7', $preamble);
        $this->assertStringContainsString('Do NOT call lookup_inventory_item', $preamble);
    }

    public function test_preamble_injects_narration_ids_for_bank_transaction(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('narration', '✅ Head created.');
        $bb->setMeta('narration_head_id', 5);
        $bb->setMeta('narration_sub_head_id', 12);

        $preamble = $bb->buildContextPreamble('bank_transaction');

        $this->assertStringContainsString('narration_head_id = 5', $preamble);
        $this->assertStringContainsString('narration_sub_head_id = 12', $preamble);
    }

    public function test_preamble_omits_resolved_ids_block_when_no_meta_set(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', '✅ Client created.');
        // No setMeta calls

        $preamble = $bb->buildContextPreamble('invoice');

        $this->assertStringNotContainsString('resolved IDs', $preamble);
    }

    public function test_preamble_contains_sentinel_header_for_agent_dependency_check(): void
    {
        $bb = new AgentContextBlackboard();
        $bb->record('client', '✅ Client created.');

        $preamble = $bb->buildContextPreamble('invoice');

        // InvoiceAgent's BLACKBOARD DEPENDENCY CHECK looks for this exact header
        $this->assertStringContainsString('PRIOR AGENT CONTEXT', $preamble);
        $this->assertStringContainsString('treat as established fact', $preamble);
    }
}
