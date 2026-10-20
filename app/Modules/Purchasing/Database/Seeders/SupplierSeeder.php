<?php

namespace App\Modules\Purchasing\Database\Seeders;

use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            ['EG-DEMO-001', 'Nile Horizon Electronics', 'Ahmed Hassan', '+20 10 5550 0101', 'electronics@example.com', 'Cairo', '18 Demo Commerce Street, Nasr City', 0, true, 'Electronics distributor'],
            ['EG-DEMO-002', 'Delta Circuit Accessories', 'Mona Adel', '+20 11 5550 0102', 'accessories@example.net', 'Alexandria', '24 Demo Technology Street, Smouha', 7, true, 'Computer accessories supplier'],
            ['EG-DEMO-003', 'Pyramid Office Solutions', 'Karim Nabil', '+20 12 5550 0103', 'office@example.org', 'Giza', '9 Demo Business Street, Dokki', 15, true, 'Office equipment supplier'],
            ['EG-DEMO-004', 'Canal Vista Trading', 'Salma Tarek', '+20 15 5550 0104', 'trading@example.com', 'Ismailia', '31 Demo Market Street', 30, true, 'General trading company'],
            ['EG-DEMO-005', 'Lotus Pack Supplies', 'Omar Sami', '+20 10 5550 0105', 'packaging@example.net', '10th of Ramadan', '12 Demo Industrial Avenue', 60, true, 'Packaging supplier'],
            ['EG-DEMO-006', 'Delta Forge Hardware', 'Nour Mostafa', '+20 11 5550 0106', 'hardware@example.org', 'Mansoura', '7 Demo Workshop Street', 7, true, 'Hardware and tools supplier'],
            ['EG-DEMO-007', 'Oasis Network Equipment', 'Youssef Amin', '+20 12 5550 0107', 'network@example.com', '6th of October', '44 Demo Distribution Road', 30, true, 'Network equipment supplier'],
            ['EG-DEMO-008', 'Fayoum Paper Works', 'Dina Hany', '+20 15 5550 0108', 'paper@example.net', 'Fayoum', '16 Demo Stationery Street', 15, true, 'Paper and stationery supplier'],
            ['EG-DEMO-009', 'Aswan Workshop Supply', 'Mahmoud Fathy', '+20 10 5550 0109', 'workshop@example.org', 'Aswan', '22 Demo Crafts Street', 0, false, 'Inactive supplier retained for historical lookup'],
            ['EG-DEMO-010', 'Suez Display Materials', 'Heba Ashraf', '+20 11 5550 0110', 'display@example.com', 'Suez', '5 Demo Warehouse Road', 60, false, 'Inactive seasonal display supplier'],
        ];
        DB::transaction(function () use ($suppliers): void {
            foreach ($suppliers as [$code, $name, $contact, $phone, $email, $city, $address, $terms, $active, $scenario]) {
                Supplier::firstOrCreate(['code' => $code], [
                    'name' => $name,
                    'contact' => $contact,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address.', '.$city.', Egypt',
                    'tax_number' => null,
                    'payment_terms_days' => $terms,
                    'is_active' => $active,
                    'notes' => 'Fictional Egyptian demo supplier. '.$scenario.'.',
                ]);
            }
        });
    }
}
