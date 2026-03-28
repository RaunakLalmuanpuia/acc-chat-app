<?php

namespace App\Ai\Tools\Company;

use App\Ai\Tools\BaseTool;
use App\Services\CompanyService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateCompany extends BaseTool
{
    protected CompanyService $service;

    public function __construct(protected User $user)
    {
        $this->service = new CompanyService($user);
    }

    protected function purpose(): string
    {
        return 'Update one or more fields on the existing company profile — only the fields you pass are changed.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to change any company detail: name, GST/PAN,
        address, contact info, bank details, or invoice footer note.

        Do NOT call this to create a company — use CreateCompany.
        Do NOT pass fields that should not change — only include what the user asked to update.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        All fields are optional — pass only what needs updating.

        Bank fields use prefixed names (bank_account_name, bank_account_number,
        bank_ifsc_code, bank_name, bank_branch) to distinguish them from company
        contact fields. Do not confuse these with the unprefixed names used in CreateCompany.

        is_active:
          - Set false to deactivate the company. Use only if the user explicitly requests it.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Update GST number:
          Input:  { "gst_number": "27AABCA9999A1ZX" }
          Output: { "success": true, "message": "Company profile updated successfully.",
                    "updated_fields": ["gst_number"] }

        Update bank details:
          Input:  { "bank_name": "ICICI Bank", "bank_account_number": "009900990099",
                    "bank_ifsc_code": "ICIC0001234" }
          Output: { "success": true, "updated_fields": ["bank_name", "bank_account_number", "bank_ifsc_code"] }

        Nothing to update:
          Input:  {}
          Output: { "success": false, "message": "No valid fields were provided to update." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode([
                'success' => false,
                'message' => 'No company profile found. Please create one first using create_company.',
            ]);
        }

        $updates = $this->service->extractUpdates((array) $request);

        if (empty($updates)) {
            return json_encode([
                'success' => false,
                'message' => 'No valid fields were provided to update.',
            ]);
        }

        $this->service->updateCompany($company, $updates);

        return json_encode([
            'success'        => true,
            'message'        => 'Company profile updated successfully.',
            'updated_fields' => array_keys($updates),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_name'        => $schema->string(),
            'gst_number'          => $schema->string(),
            'pan_number'          => $schema->string(),
            'state'               => $schema->string(),
            'state_code'          => $schema->string(),
            'address'             => $schema->string(),
            'city'                => $schema->string(),
            'pincode'             => $schema->string(),
            'country'             => $schema->string(),
            'email'               => $schema->string(),
            'phone'               => $schema->string(),
            'website'             => $schema->string(),
            'bank_account_name'   => $schema->string(),
            'bank_account_number' => $schema->string(),
            'bank_ifsc_code'      => $schema->string(),
            'bank_name'           => $schema->string(),
            'bank_branch'         => $schema->string(),
            'invoice_footer_note' => $schema->string(),
            'is_active'           => $schema->boolean(),
        ];
    }
}
