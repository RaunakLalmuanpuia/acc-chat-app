<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * BaseTool  (v1 — Anthropic ACI principles, Appendix 2)
 *
 * Abstract base class for all tools in the accounting assistant.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The Anthropic "Building Effective Agents" guide (Appendix 2: Prompt
 * Engineering your Tools) states:
 *
 *   "One rule of thumb is to think about how much effort goes into
 *    human-computer interfaces (HCI), and plan to invest just as much
 *    effort in creating good agent-computer interfaces (ACI)."
 *
 *   "A good tool definition often includes example usage, edge cases,
 *    input format requirements, and clear boundaries from other tools."
 *
 *   "Poka-yoke your tools. Change the arguments so that it is harder
 *    to make mistakes."
 *
 *   "For SWE-bench we actually spent more time optimizing our tools
 *    than the overall prompt."
 *
 * Source: anthropic.com/engineering/building-effective-agents
 *
 * The SDK's `Tool` contract only requires `description()`, `handle()`,
 * and `schema()`. A single sentence in `description()` is not enough —
 * it is the minimum, not the target.
 *
 * This base class forces every tool author to think in four structured
 * dimensions, then automatically assembles them into a rich description:
 *
 *   purpose()     → what the tool does (required)
 *   when()        → when to call it vs. when NOT to (required)
 *   parameters()  → input format rules, constraints, edge cases (optional)
 *   examples()    → concrete call/result pairs (optional, strongly recommended)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW TO CREATE A TOOL
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. Extend BaseTool.
 *  2. Implement purpose()     — one sentence: "Searches the client list…"
 *  3. Implement when()        — "Call this when you need to find an existing
 *                               client by name or email. Do NOT call this to
 *                               create a client — use create_client instead."
 *  4. Optionally implement parameters() — document non-obvious field rules.
 *  5. Optionally implement examples()   — at least one concrete example.
 *  6. Implement schema()      — parameter definitions (SDK contract).
 *  7. Implement handle()      — execution logic (SDK contract).
 *
 *  Do NOT override description(). It is assembled automatically from the
 *  methods above to guarantee consistent ACI quality across all tools.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POKA-YOKE GUIDANCE  (Anthropic Appendix 2)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Poka-yoke = "mistake-proofing". Design schema() fields so errors are hard:
 *
 *  ✓ Use required() for fields the model must always provide.
 *  ✓ Use enum() to constrain fields to valid values where possible.
 *  ✓ Prefer clear field names: `invoice_number` not `id` when it's a human-
 *    readable number like INV-20240101-001.
 *  ✓ Use separate fields for related-but-distinct IDs (invoice_id vs
 *    invoice_number) so the model cannot mix them up.
 *  ✓ Document the distinction between IDs in parameters():
 *    "invoice_id is the integer DB primary key from create_invoice.
 *     invoice_number is the human string INV-YYYYMMDD-N.
 *     generate_invoice_pdf takes invoice_number. add_line_item takes invoice_id."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EXAMPLE IMPLEMENTATION
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  class GetClients extends BaseTool
 *  {
 *      protected function purpose(): string
 *      {
 *          return 'Search the client list by name or email.';
 *      }
 *
 *      protected function when(): string
 *      {
 *          return <<<WHEN
 *          Call this BEFORE create_client to check if the client already exists.
 *          Call this to find a client_id before creating or updating an invoice.
 *
 *          Do NOT call this after already finding the client in the same turn —
 *          reuse the client_id you already have. Never call this twice with the
 *          same query in one turn (loop guard).
 *          WHEN;
 *      }
 *
 *      protected function parameters(): string
 *      {
 *          return <<<PARAMS
 *          query (optional):
 *            - Pass the client name or email the user mentioned.
 *            - Partial matches are supported: "Acme" will match "Acme Corp".
 *            - Omit to return all clients (maximum 50).
 *            - Do NOT pass a numeric ID — this searches text fields only.
 *          PARAMS;
 *      }
 *
 *      protected function examples(): string
 *      {
 *          return <<<EXAMPLES
 *          Search by name:
 *            Input:  { "query": "Infosys" }
 *            Output: [{ "id": 14, "name": "Infosys Ltd", "email": "ar@infosys.com" }]
 *
 *          Search by email:
 *            Input:  { "query": "ar@infosys.com" }
 *            Output: [{ "id": 14, "name": "Infosys Ltd", "email": "ar@infosys.com" }]
 *
 *          Not found:
 *            Input:  { "query": "XYZ Unknown Corp" }
 *            Output: []   ← proceed to create_client
 *          EXAMPLES;
 *      }
 *
 *      // ... schema() and handle() ...
 *  }
 */
abstract class BaseTool implements Tool
{
    // ── Abstract — every tool must implement ──────────────────────────────────

    /**
     * One sentence describing what this tool does.
     *
     * This is the first thing the model reads. Make it precise.
     * Bad:  "Gets clients."
     * Good: "Search the client list by name or email, returning matching records."
     */
    abstract protected function purpose(): string;

    /**
     * When to call this tool — and crucially, when NOT to.
     *
     * Anthropic: "Clear boundaries from other tools."
     * This is where you distinguish get_clients from create_client,
     * get_invoice from get_active_drafts, lookup_client from lookup_inventory_item.
     *
     * Format: short prose paragraphs or bulleted list.
     * Must include at least one "Do NOT" boundary statement.
     */
    abstract protected function when(): string;

    // ── Optional — strongly recommended ──────────────────────────────────────

    /**
     * Document each parameter's format, constraints, and edge cases.
     *
     * Anthropic: "Input format requirements."
     *
     * Override when parameters have non-obvious rules:
     *   - What format should a date be in? (YYYY-MM-DD, not DD/MM/YYYY)
     *   - Is this the integer id or the human-readable invoice_number?
     *   - What happens if I pass null vs. omit the field?
     *   - What are valid enum values and what does each mean?
     *
     * Return an empty string if all parameters are self-explanatory from
     * the schema() field names and descriptions alone.
     */
    protected function parameters(): string
    {
        return '';
    }

    /**
     * At least one concrete call/result example.
     *
     * Anthropic: "Example usage" in tool definitions.
     *
     * Format the examples as:
     *   Action description:
     *     Input:  { field: value }
     *     Output: { ... }
     *
     * Include an "empty/not-found" example where relevant so the model
     * knows exactly what to expect when a lookup returns nothing.
     *
     * Return an empty string to omit (not recommended for tools with
     * complex outputs or multiple failure modes).
     */
    protected function examples(): string
    {
        return '';
    }

    // ── Final — do not override ───────────────────────────────────────────────

    /**
     * Assemble the full ACI-quality description from structured sections.
     *
     * The assembled description is what the model sees as the tool's
     * documentation. Section headings use plain text (not markdown) because
     * tool descriptions are typically rendered in JSON/XML by the provider
     * and markdown would appear as literal characters.
     *
     * Anthropic: "A good tool definition often includes example usage, edge
     * cases, input format requirements, and clear boundaries from other tools."
     */
    final public function description(): Stringable|string
    {
        $sections = [];

        // Section 1: Purpose (always present)
        $sections[] = trim($this->purpose());

        // Section 2: When to use / not use (always present)
        $sections[] = "WHEN TO USE:\n" . trim($this->when());

        // Section 3: Parameter details (optional)
        $params = trim($this->parameters());
        if ($params !== '') {
            $sections[] = "PARAMETER DETAILS:\n" . $params;
        }

        // Section 4: Examples (optional)
        $examples = trim($this->examples());
        if ($examples !== '') {
            $sections[] = "EXAMPLES:\n" . $examples;
        }

        return implode("\n\n", $sections);
    }

    // ── SDK contract — subclasses implement these ─────────────────────────────

    abstract public function handle(Request $request): Stringable|string;

    abstract public function schema(JsonSchema $schema): array;
}
