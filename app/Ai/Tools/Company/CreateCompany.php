<?php

namespace App\Ai\Tools\Company;

use App\Ai\Tools\BaseTool;
use App\Services\CompanyService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateCompany extends BaseTool
{
    protected CompanyService $service;

    public function __construct(protected User $user)
    {
        $this->service = new CompanyService($user);
    }

    protected function purpose(): string
    {
        return 'Create a new company profile for the user, including optional GST/PAN details, address, contact info, and bank account.';
    }

    protected function when(): string
    {
        return <<<WHEN
        Call this when the user wants to set up their company for the first time.

        Do NOT call this if the user already has a company — the tool will block it and
        return an error. Use GetCompany to check first if unsure.
        Do NOT call this to update an existing company — use UpdateCompany instead.
        WHEN;
    }

    protected function parameters(): string
    {
        return <<<PARAMS
        company_name (required):
          - Legal or trading name of the business. All other fields are optional at creation.

        state / state_code:
          - Indian state name and two-digit GST state code (e.g. "Maharashtra" / "27").
          - Required for correct GST supply-type determination on invoices.

        Bank account fields (all optional at creation):
          - account_name, bank_name, account_number, ifsc_code, branch, account_type.
          - account_type: "current" (default) or "savings".
          - currency: ISO code, defaults to INR.
          - opening_balance: decimal amount; opening_balance_date in YYYY-MM-DD.

        country:
          - Defaults to "India" if omitted.
        PARAMS;
    }

    protected function examples(): string
    {
        return <<<EXAMPLES
        Minimal creation:
          Input:  { "company_name": "Acme Exports Pvt Ltd" }
          Output: { "success": true, "message": "Company profile created successfully.", "company": { ... } }

        Full creation with GST and bank details:
          Input:  { "company_name": "Acme Exports Pvt Ltd", "gst_number": "27AABCA1234A1ZX",
                    "state": "Maharashtra", "state_code": "27",
                    "bank_name": "HDFC Bank", "account_number": "001234567890",
                    "ifsc_code": "HDFC0001234", "account_type": "current" }
          Output: { "success": true, "message": "Company profile created successfully.", "company": { ... } }

        Already has a company:
          Output: { "success": false, "message": "You already have a company profile. Use update_company to make changes." }
        EXAMPLES;
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->service->hasCompany()) {
            return json_encode([
                'success' => false,
                'message' => 'You already have a company profile. Use update_company to make changes.',
            ]);
        }

        $required = ['company_name'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                return json_encode([
                    'success' => false,
                    'message' => "The field '{$field}' is required to create a company.",
                ]);
            }
        }

        $data = array_filter([
            'company_name'         => $request['company_name'],
            'gst_number'           => $request['gst_number']           ?? null,
            'pan_number'           => $request['pan_number']           ?? null,
            'state'                => $request['state']                ?? null,
            'state_code'           => $request['state_code']           ?? null,
            'address'              => $request['address']              ?? null,
            'city'                 => $request['city']                 ?? null,
            'pincode'              => $request['pincode']              ?? null,
            'country'              => $request['country']              ?? 'India',
            'email'                => $request['email']                ?? null,
            'phone'                => $request['phone']                ?? null,
            'website'              => $request['website']              ?? null,
            'invoice_footer_note'  => $request['invoice_footer_note']  ?? null,
            'account_name'         => $request['account_name']         ?? null,
            'bank_name'            => $request['bank_name']            ?? null,
            'account_number'       => $request['account_number']       ?? null,
            'ifsc_code'            => $request['ifsc_code']            ?? null,
            'branch'               => $request['branch']               ?? null,
            'account_type'         => $request['account_type']         ?? 'current',
            'currency'             => $request['currency']             ?? 'INR',
            'opening_balance'      => $request['opening_balance']      ?? null,
            'opening_balance_date' => $request['opening_balance_date'] ?? null,
        ], fn($v) => $v !== null);

        $company = $this->service->createCompany($data);

        return json_encode([
            'success' => true,
            'message' => 'Company profile created successfully.',
            'company' => $this->service->formatCompany($company),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'company_name'         => $schema->string()->description('Legal name of the company (required)'),
            'gst_number'           => $schema->string()->description('GST registration number'),
            'pan_number'           => $schema->string()->description('PAN number'),
            'state'                => $schema->string()->description('State name'),
            'state_code'           => $schema->string()->description('2-digit GST state code, e.g. "27" for Maharashtra'),
            'address'              => $schema->string()->description('Street address'),
            'city'                 => $schema->string()->description('City'),
            'pincode'              => $schema->string()->description('PIN code'),
            'country'              => $schema->string()->description('Country (defaults to India)'),
            'email'                => $schema->string()->description('Business email'),
            'phone'                => $schema->string()->description('Business phone'),
            'website'              => $schema->string()->description('Business website'),
            'invoice_footer_note'  => $schema->string()->description('Footer note shown on all invoices'),
            'account_name'         => $schema->string()->description('Name on the bank account'),
            'bank_name'            => $schema->string()->description('Name of the bank'),
            'account_number'       => $schema->string()->description('Bank account number'),
            'ifsc_code'            => $schema->string()->description('Bank IFSC code'),
            'branch'               => $schema->string()->description('Bank branch name'),
            'account_type'         => $schema->string()->description('Account type: current (default) or savings'),
            'currency'             => $schema->string()->description('Currency code (default: INR)'),
            'opening_balance'      => $schema->number()->description('Opening bank balance amount'),
            'opening_balance_date' => $schema->string()->description('Opening balance date (YYYY-MM-DD)'),
        ];
    }
}
