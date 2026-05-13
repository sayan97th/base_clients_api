<?php

namespace Database\Seeders;

use App\Models\CreditPackage;
use Illuminate\Database\Seeder;

class CreditPackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'id'             => 'credits_3000',
                'name'           => 'One-Time — 3,000 Credits',
                'credits'        => 3000,
                'price'          => 2700.00,
                'original_price' => 3000.00,
                'discount_pct'   => 10,
                'description'    => 'Purchase 3,000 credits at a 10% discount.',
                'is_popular'     => false,
                'is_active'      => true,
            ],
            [
                'id'             => 'credits_5000',
                'name'           => 'One-Time — 5,000 Credits',
                'credits'        => 5000,
                'price'          => 4500.00,
                'original_price' => 5000.00,
                'discount_pct'   => 10,
                'description'    => 'Purchase 5,000 credits at a 10% discount.',
                'is_popular'     => true,
                'is_active'      => true,
            ],
            [
                'id'             => 'credits_10000',
                'name'           => 'One-Time — 10,000 Credits',
                'credits'        => 10000,
                'price'          => 9000.00,
                'original_price' => 10000.00,
                'discount_pct'   => 10,
                'description'    => 'Purchase 10,000 credits at a 10% discount.',
                'is_popular'     => false,
                'is_active'      => true,
            ],
        ];

        foreach ($packages as $package) {
            CreditPackage::updateOrCreate(['id' => $package['id']], $package);
        }
    }
}
