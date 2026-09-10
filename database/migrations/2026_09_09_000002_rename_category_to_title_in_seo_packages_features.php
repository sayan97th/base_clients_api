<?php

use App\Models\SeoPackage;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SeoPackage::query()->each(function (SeoPackage $package): void {
            $features = collect($package->features ?? [])
                ->map(function (array $feature): array {
                    if (array_key_exists('category', $feature) && !array_key_exists('title', $feature)) {
                        $feature['title'] = $feature['category'];
                    }
                    unset($feature['category']);

                    return $feature;
                })
                ->values()
                ->all();

            $package->update(['features' => $features]);
        });
    }

    public function down(): void
    {
        SeoPackage::query()->each(function (SeoPackage $package): void {
            $features = collect($package->features ?? [])
                ->map(function (array $feature): array {
                    if (array_key_exists('title', $feature) && !array_key_exists('category', $feature)) {
                        $feature['category'] = $feature['title'];
                    }
                    unset($feature['title']);

                    return $feature;
                })
                ->values()
                ->all();

            $package->update(['features' => $features]);
        });
    }
};
