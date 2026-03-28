<?php

namespace App\Ai\Services;

/**
 * ResponseMergerService  (v3 — Fix 11: canonical section ordering)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v2
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 11 — section order was non-deterministic after HANDOFF / invoice-waiting
 * filtering:
 *
 *   v2 iterated the raw $responses array, which preserves PHP insertion order
 *   (setup intents first, then primary). After HANDOFF filtering and invoice-
 *   waiting suppression, the surviving entries could appear in any subset
 *   ordering. The user might see "### 🧾 Invoice" before "### 👤 Client" or
 *   "### 📦 Inventory" depending on which entries survived — confusing and
 *   inconsistent across identical multi-agent turns.
 *
 *   Fix: apply a canonical display sort before building $contentParts.
 *   The sort order mirrors the natural reading flow of an accounting workflow:
 *   setup resources first (client, inventory, narration) then primary outcomes
 *   (bank transactions, invoice) then business profile last.
 *
 *   DISPLAY_ORDER is intentionally separate from AgentRegistry::AGENTS so
 *   the display ordering can evolve independently of the dispatch ordering.
 *
 * All v2 features preserved:
 *   - HANDOFF suppression
 *   - Pending signal consolidation (⏳ footer)
 *   - Single-content shortcut (no section headers for one surviving reply)
 *   - Error reply suppression with unified retry message
 */
class ResponseMergerService
{
    private const UNKNOWN_RESPONSE =
        "I'm your accounting assistant. I can help you with:\n\n"
        . "• 🧾 **Invoices** — create, confirm, view, or generate PDFs\n"
        . "• 👤 **Clients** — add, update, or look up client records\n"
        . "• 📦 **Inventory** — manage products and services\n"
        . "• 📒 **Narration Heads** — set up transaction categories\n"
        . "• 🏢 **Business Profile** — view or update your business details\n"
        . "• 🏦 **Bank Transactions** — review, categorise, reconcile transactions\n\n"
        . "How can I help you today?";

    private const SECTION_LABELS = [
        'client'           => '👤 Client',
        'inventory'        => '📦 Inventory',
        'narration'        => '📒 Narration Heads',
        'bank_transaction' => '🏦 Bank Transactions',
        'invoice'          => '🧾 Invoice',
        'business'         => '🏢 Business Profile',
    ];

    /**
     * FIX 11 — canonical display order.
     *
     * Setup resources (client, inventory, narration) appear first so the user
     * reads prerequisite confirmations before seeing the invoice outcome.
     * bank_transaction precedes invoice because transaction categorisation is
     * the setup for reconciliation. business is last as it is rarely the
     * primary outcome in a multi-agent turn.
     *
     * Any unknown intents not listed here are appended after all known intents.
     */
    private const DISPLAY_ORDER = [
        'client',
        'inventory',
        'narration',
        'bank_transaction',
        'invoice',
        'business',
    ];

    public function merge(array $responses): string
    {
        // Strip HANDOFF signals
        $responses = array_filter(
            $responses,
            fn($r) => trim($r) !== 'HANDOFF'
        );

        if (empty($responses)) {
            return '';
        }

        if (count($responses) === 1) {
            return reset($responses);
        }

        return $this->mergeMultiple($responses);
    }

    public function unknownResponse(): string
    {
        return self::UNKNOWN_RESPONSE;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function mergeMultiple(array $responses): string
    {
        // FIX 11: sort into canonical display order before building sections.
        // Entries not in DISPLAY_ORDER are appended at the end in their
        // original relative order.
        uksort($responses, function (string $a, string $b): int {
            $orderA = array_search($a, self::DISPLAY_ORDER, true);
            $orderB = array_search($b, self::DISPLAY_ORDER, true);

            $posA = $orderA !== false ? $orderA : PHP_INT_MAX;
            $posB = $orderB !== false ? $orderB : PHP_INT_MAX;

            return $posA <=> $posB;
        });

        $contentParts     = [];
        $hasPendingSignal = false;
        $hasError         = false;

        foreach ($responses as $intent => $reply) {
            $label = self::SECTION_LABELS[$intent] ?? ucfirst($intent);

            if ($intent === 'invoice' && $this->isInvoiceWaiting($reply)) {
                continue;
            }

            if ($this->isErrorReply($reply)) {
                $hasError = true;
                continue;
            }

            if (str_contains($reply, '⏳')) {
                $hasPendingSignal = true;
            }

            $cleanReply     = $this->stripPendingSignal($reply);
            $contentParts[] = "### {$label}\n\n{$cleanReply}";
        }

        if (empty($contentParts) && $hasError) {
            return "I'm processing a lot right now — please send your message again in a few seconds.";
        }

        if (empty($contentParts)) {
            return "I'll create the invoice once the details above are confirmed.";
        }

        $merged = count($contentParts) === 1
            ? ltrim(preg_replace('/^### [^\n]+\n\n/', '', reset($contentParts)))
            : implode("\n\n---\n\n", $contentParts);

        if ($hasPendingSignal) {
            $merged .= "\n\n---\n\n⏳ **Once I have these details, I'll create both records and generate your invoice automatically.**";
        }

        if ($hasError) {
            $merged .= "\n\n---\n\n⚠️ The invoice step hit a rate limit. Your client and inventory were saved — **please send your message again** to complete the invoice.";
        }

        return $merged;
    }

    private function isErrorReply(string $reply): bool
    {
        return str_contains($reply, 'I encountered an issue')
            || str_contains($reply, 'please try again in a moment')
            || str_contains($reply, 'processing a lot right now');
    }

    private function isInvoiceWaiting(string $reply): bool
    {
        $lower = strtolower(trim($reply));
        return str_contains($lower, "once the") && str_contains($lower, "i'll proceed");
    }

    private function stripPendingSignal(string $reply): string
    {
        return trim(preg_replace('/\n?⏳[^\n]+/', '', $reply));
    }
}
