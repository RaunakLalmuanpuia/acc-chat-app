<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\InventoryItem;

class InventoryItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->itemDefinitions() as $gstNumber => $items) {
            $company = Company::where('gst_number', $gstNumber)->firstOrFail();

            foreach ($items as $item) {
                InventoryItem::updateOrCreate(
                    ['company_id' => $company->id, 'name' => $item['name']],
                    array_merge($item, ['company_id' => $company->id])
                );
            }

            $this->command?->info("  ✓ Inventory items seeded for: {$company->company_name}");
        }
    }

    private function itemDefinitions(): array
    {
        return [
            '27AAAAA7777A1Z5' => [
                ['name' => 'Television',  'category' => 'Electronics', 'hsn_code' => '8528', 'unit' => 'Unit',  'rate' => 55000, 'gst_rate' => 18],
                ['name' => 'Mixer',      'category' => 'Electronics', 'hsn_code' => '8509', 'unit' => 'Unit',  'rate' => 3500,  'gst_rate' => 18],

                ['name' => 'Rice',       'category' => 'Grocery',     'hsn_code' => '1006', 'unit' => 'Bag',   'rate' => 850,   'gst_rate' => 5],
                ['name' => 'Oil',        'category' => 'Grocery',     'hsn_code' => '1512', 'unit' => 'Pouch', 'rate' => 150,   'gst_rate' => 5],

                ['name' => 'Jeans',      'category' => 'Apparel',     'hsn_code' => '6203', 'unit' => 'Pair',  'rate' => 2500,  'gst_rate' => 12],
                ['name' => 'Shirt',      'category' => 'Apparel',     'hsn_code' => '6105', 'unit' => 'Piece', 'rate' => 500,   'gst_rate' => 12],

                ['name' => 'Cooker',     'category' => 'Home',        'hsn_code' => '7615', 'unit' => 'Unit',  'rate' => 1800,  'gst_rate' => 12],
            ],

            '29BBBBB8888B1Z6' => [
                ['name' => 'HRMS',       'category' => 'SaaS',     'hsn_code' => '998314', 'unit' => 'License', 'rate' => 5000,  'gst_rate' => 18],
                ['name' => 'Payroll',    'category' => 'SaaS',     'hsn_code' => '998314', 'unit' => 'License', 'rate' => 50000, 'gst_rate' => 18],
                ['name' => 'Addon',      'category' => 'SaaS',     'hsn_code' => '998314', 'unit' => 'License', 'rate' => 2000,  'gst_rate' => 18],

                ['name' => 'Consulting', 'category' => 'Services', 'hsn_code' => '998313', 'unit' => 'Hour',    'rate' => 3500,  'gst_rate' => 18],
                ['name' => 'Development','category' => 'Services', 'hsn_code' => '998313', 'unit' => 'Sprint',  'rate' => 75000, 'gst_rate' => 18],
                ['name' => 'Integration','category' => 'Services', 'hsn_code' => '998313', 'unit' => 'Package', 'rate' => 25000, 'gst_rate' => 18],

                ['name' => 'Support',    'category' => 'Support',  'hsn_code' => '998314', 'unit' => 'Year',    'rate' => 12000, 'gst_rate' => 18],
            ],
        ];
    }
}
