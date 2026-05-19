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
                'price'          => 2640.00,
                'original_price' => 3000.00,
                'discount_pct'   => 12,
                'description'    => 'Perfect for getting started with BASE services.',
                'is_popular'     => false,
                'is_active'      => true,
            ],
            [
                'id'             => 'credits_5000',
                'name'           => 'One-Time — 5,000 Credits',
                'credits'        => 5000,
                'price'          => 4400.00,
                'original_price' => 5000.00,
                'discount_pct'   => 12,
                'description'    => 'Our most popular bundle for growing teams.',
                'is_popular'     => true,
                'is_active'      => true,
            ],
            [
                'id'             => 'credits_10000',
                'name'           => 'One-Time — 10,000 Credits',
                'credits'        => 10000,
                'price'          => 8800.00,
                'original_price' => 10000.00,
                'discount_pct'   => 12,
                'description'    => 'Maximum value for high-volume users.',
                'is_popular'     => false,
                'is_active'      => true,
            ],
        ];

        foreach ($packages as $package) {
            CreditPackage::updateOrCreate(['id' => $package['id']], $package);
        }
    }
}
