<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\Client;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->clientDefinitions() as $gstNumber => $clients) {
            $company = Company::where('gst_number', $gstNumber)->firstOrFail();

            foreach ($clients as $data) {
                Client::updateOrCreate(
                    ['company_id' => $company->id, 'email' => $data['email']],
                    array_merge($data, ['company_id' => $company->id])
                );
            }

            $this->command?->info("  ✓ Clients seeded for: {$company->company_name}");
        }
    }

    private function clientDefinitions(): array
    {
        return [
            '27AAAAA7777A1Z5' => [
                [
                    'name' => 'Alpha',
                    'email' => 'alpha@test.com',
                    'phone' => '9000000001',
                    'gst_number' => '29AAAAA5678A1Z5',
                    'gst_type' => 'regular',
                    'address' => 'Address1',
                    'city' => 'Bangalore',
                    'state' => 'Karnataka',
                    'state_code' => '29',
                    'pincode' => '560001',
                    'payment_terms' => 'Net 30',
                    'is_active' => true,
                ],
                [
                    'name' => 'Beta',
                    'email' => 'beta@test.com',
                    'phone' => '9000000002',
                    'gst_number' => '27BBBBB1234B1Z6',
                    'gst_type' => 'regular',
                    'address' => 'Address2',
                    'city' => 'Mumbai',
                    'state' => 'Maharashtra',
                    'state_code' => '27',
                    'pincode' => '400001',
                    'payment_terms' => 'Net 30',
                    'is_active' => true,
                ],
                [
                    'name' => 'Gamma',
                    'email' => 'gamma@test.com',
                    'phone' => '9000000003',
                    'gst_number' => '06CCCCC9876C1Z7',
                    'gst_type' => 'regular',
                    'address' => 'Address3',
                    'city' => 'Gurgaon',
                    'state' => 'Haryana',
                    'state_code' => '06',
                    'pincode' => '122001',
                    'payment_terms' => 'Net 15',
                    'is_active' => true,
                ],
            ],

            '29BBBBB8888B1Z6' => [
                [
                    'name' => 'Delta',
                    'email' => 'delta@test.com',
                    'phone' => '9000000011',
                    'gst_number' => '29DDDDD1111D1Z8',
                    'gst_type' => 'regular',
                    'address' => 'AddressA',
                    'city' => 'Bangalore',
                    'state' => 'Karnataka',
                    'state_code' => '29',
                    'pincode' => '560002',
                    'payment_terms' => 'Net 30',
                    'is_active' => true,
                ],
                [
                    'name' => 'Epsilon',
                    'email' => 'epsilon@test.com',
                    'phone' => '9000000012',
                    'gst_number' => '29EEEEE2222E1Z9',
                    'gst_type' => 'regular',
                    'address' => 'AddressB',
                    'city' => 'Bangalore',
                    'state' => 'Karnataka',
                    'state_code' => '29',
                    'pincode' => '560003',
                    'payment_terms' => 'Net 15',
                    'is_active' => true,
                ],
                [
                    'name' => 'Zeta',
                    'email' => 'zeta@test.com',
                    'phone' => '9000000013',
                    'gst_number' => '29FFFFF3333F1Z0',
                    'gst_type' => 'regular',
                    'address' => 'AddressC',
                    'city' => 'Bangalore',
                    'state' => 'Karnataka',
                    'state_code' => '29',
                    'pincode' => '560004',
                    'payment_terms' => 'Net 30',
                    'is_active' => true,
                ],
            ],
        ];
    }
}
