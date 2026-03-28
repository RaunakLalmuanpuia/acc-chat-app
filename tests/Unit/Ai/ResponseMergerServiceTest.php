<?php

namespace Tests\Unit\Ai;

use App\Ai\Services\ResponseMergerService;
use Tests\TestCase;

class ResponseMergerServiceTest extends TestCase
{
    private ResponseMergerService $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new ResponseMergerService();
    }

    // ── Single response ────────────────────────────────────────────────────────

    public function test_single_response_returned_as_is(): void
    {
        $result = $this->merger->merge(['invoice' => 'Invoice INV-001 created.']);

        $this->assertSame('Invoice INV-001 created.', $result);
    }

    public function test_handoff_only_returns_empty_string(): void
    {
        $result = $this->merger->merge(['client' => 'HANDOFF']);

        $this->assertSame('', $result);
    }

    // ── HANDOFF filtering ─────────────────────────────────────────────────────

    public function test_handoff_stripped_leaving_invoice_reply(): void
    {
        $result = $this->merger->merge([
            'client'  => 'HANDOFF',
            'invoice' => 'INV-20260101-1 created for Infosys.',
        ]);

        $this->assertStringNotContainsString('HANDOFF', $result);
        $this->assertStringContainsString('INV-20260101-1', $result);
    }

    public function test_handoff_with_whitespace_is_stripped(): void
    {
        $result = $this->merger->merge([
            'client'  => '  HANDOFF  ',
            'invoice' => 'Invoice created.',
        ]);

        $this->assertStringNotContainsString('HANDOFF', $result);
    }

    // ── Invoice-waiting suppression ────────────────────────────────────────────

    public function test_invoice_waiting_reply_is_suppressed(): void
    {
        $result = $this->merger->merge([
            'client'  => '⏳ Please provide the email.',
            'invoice' => "Once the details are confirmed, I'll proceed with creating the invoice.",
        ]);

        $this->assertStringNotContainsString("I'll proceed", $result);
        $this->assertStringContainsString('email', $result);
    }

    // ── Canonical section ordering (Fix 11) ───────────────────────────────────

    public function test_sections_appear_in_canonical_order(): void
    {
        // Responses keyed in reverse of canonical order
        $result = $this->merger->merge([
            'invoice'   => 'Invoice created.',
            'inventory' => 'Chair added.',
            'client'    => 'Client created.',
        ]);

        $clientPos    = strpos($result, '👤 Client');
        $inventoryPos = strpos($result, '📦 Inventory');
        $invoicePos   = strpos($result, '🧾 Invoice');

        $this->assertLessThan($inventoryPos, $clientPos,  'Client should appear before Inventory');
        $this->assertLessThan($invoicePos,   $inventoryPos, 'Inventory should appear before Invoice');
    }

    public function test_bank_transaction_appears_before_invoice(): void
    {
        $result = $this->merger->merge([
            'invoice'          => 'Invoice created.',
            'bank_transaction' => 'Transactions listed.',
        ]);

        $bankPos    = strpos($result, '🏦 Bank Transactions');
        $invoicePos = strpos($result, '🧾 Invoice');

        $this->assertLessThan($invoicePos, $bankPos);
    }

    public function test_business_appears_last(): void
    {
        $result = $this->merger->merge([
            'business' => 'Profile updated.',
            'client'   => 'Client created.',
        ]);

        $clientPos   = strpos($result, '👤 Client');
        $businessPos = strpos($result, '🏢 Business Profile');

        $this->assertLessThan($businessPos, $clientPos);
    }

    // ── Single surviving content (no headers) ─────────────────────────────────

    public function test_single_surviving_content_has_no_section_header(): void
    {
        // After HANDOFF filtering, only invoice survives
        $result = $this->merger->merge([
            'client'  => 'HANDOFF',
            'invoice' => 'INV-001 created.',
        ]);

        $this->assertStringNotContainsString('### 🧾 Invoice', $result);
        $this->assertStringContainsString('INV-001 created.', $result);
    }

    // ── Pending signal (⏳) consolidation ──────────────────────────────────────

    public function test_pending_signal_appended_as_footer(): void
    {
        $result = $this->merger->merge([
            'client'    => '⏳ Please provide the email.',
            'inventory' => '⏳ Please provide the rate.',
            'invoice'   => "Once the details are confirmed, I'll proceed with creating the invoice.",
        ]);

        $this->assertStringContainsString('⏳', $result);
        $this->assertStringContainsString("I'll create both records", $result);
    }

    public function test_pending_signal_stripped_from_individual_sections(): void
    {
        $result = $this->merger->merge([
            'client'  => "What is the email? ⏳ Once I have these details, the invoice will proceed.",
            'invoice' => "Once the details are confirmed, I'll proceed.",
        ]);

        // The inline ⏳ signal should be stripped from the section body
        // and a consolidated footer added instead
        $lines = explode("\n", $result);
        foreach ($lines as $line) {
            if (str_contains($line, '⏳')) {
                // Only the consolidated footer should contain ⏳
                $this->assertStringContainsString("I'll create both records", $line);
            }
        }
    }

    // ── Error reply handling ───────────────────────────────────────────────────

    public function test_error_reply_suppressed_with_retry_footer(): void
    {
        $result = $this->merger->merge([
            'client'  => '✅ Client created.',
            'invoice' => 'I encountered an issue with Invoice operations. Please try again in a moment.',
        ]);

        $this->assertStringNotContainsString('### 🧾 Invoice', $result);
        $this->assertStringContainsString('rate limit', $result);
        $this->assertStringContainsString('please send your message again', $result);
    }

    public function test_all_errors_returns_generic_retry_message(): void
    {
        $result = $this->merger->merge([
            'client'  => 'I encountered an issue with Client operations. Please try again in a moment.',
            'invoice' => "I'm processing a lot right now — please send your message again in a few seconds.",
        ]);

        $this->assertStringContainsString("processing a lot right now", $result);
    }

    public function test_all_waiting_with_no_content_returns_placeholder(): void
    {
        $result = $this->merger->merge([
            'client'  => 'HANDOFF',
            'invoice' => "Once the details are confirmed, I'll proceed.",
        ]);

        $this->assertStringContainsString("I'll create the invoice", $result);
    }

    // ── unknownResponse ────────────────────────────────────────────────────────

    public function test_unknown_response_contains_all_domains(): void
    {
        $response = $this->merger->unknownResponse();

        $this->assertStringContainsString('Invoices', $response);
        $this->assertStringContainsString('Clients', $response);
        $this->assertStringContainsString('Inventory', $response);
        $this->assertStringContainsString('Bank Transactions', $response);
    }

    // ── Section labels ─────────────────────────────────────────────────────────

    public function test_all_known_intents_have_emoji_labels(): void
    {
        $result = $this->merger->merge([
            'client'           => 'Client reply.',
            'inventory'        => 'Inventory reply.',
            'narration'        => 'Narration reply.',
            'bank_transaction' => 'Bank reply.',
            'invoice'          => 'Invoice reply.',
            'business'         => 'Business reply.',
        ]);

        $this->assertStringContainsString('👤 Client', $result);
        $this->assertStringContainsString('📦 Inventory', $result);
        $this->assertStringContainsString('📒 Narration Heads', $result);
        $this->assertStringContainsString('🏦 Bank Transactions', $result);
        $this->assertStringContainsString('🧾 Invoice', $result);
        $this->assertStringContainsString('🏢 Business Profile', $result);
    }
}
