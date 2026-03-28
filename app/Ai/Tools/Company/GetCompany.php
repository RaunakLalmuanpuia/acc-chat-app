<?php

namespace App\Ai\Tools\Company;

use App\Ai\Tools\BaseTool;
use App\Services\CompanyService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetCompany extends BaseTool
{
    protected CompanyService $service;

    public function __construct(protected User $user)
    {
        $this->service = new CompanyService($user);
    }

    protected function purpose(): string
    {
        return 'Retrieve the current user\'s company profile: name, GST/PAN, address, bank details, and contact information.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user asks about their company details, or when you need to
        verify whether a company profile exists before calling CreateCompany.

        Do NOT call this repeatedly in the same turn — cache the result and reuse it.
        This tool takes no parameters; calling it multiple times returns the same data.
        WHEN;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Company exists:
          Input:  {}
          Output: { "found": true, "company": { "company_name": "Acme Exports Pvt Ltd",
                    "gst_number": "27AABCA1234A1ZX", "state": "Maharashtra", ... } }

        No company yet:
          Input:  {}
          Output: { "found": false,
                    "message": "No company profile found. You can create one by providing the necessary details." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode([
                'found'   => false,
                'message' => 'No company profile found. You can create one by providing the necessary details.',
            ]);
        }

        return json_encode([
            'found'   => true,
            'company' => $this->service->formatCompany($company),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
