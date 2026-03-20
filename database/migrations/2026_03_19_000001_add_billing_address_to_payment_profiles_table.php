<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_profiles', function (Blueprint $table) {
            $table->string('billing_address_line1', 255)->nullable()->after('cardholder_name');
            $table->string('billing_address_city', 255)->nullable()->after('billing_address_line1');
            $table->string('billing_address_state', 255)->nullable()->after('billing_address_city');
            $table->string('billing_address_postal', 20)->nullable()->after('billing_address_state');
            $table->char('billing_address_country', 2)->nullable()->after('billing_address_postal');
            $table->string('billing_address_company', 255)->nullable()->after('billing_address_country');
        });
    }

    public function down(): void
    {
        Schema::table('payment_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'billing_address_line1',
                'billing_address_city',
                'billing_address_state',
                'billing_address_postal',
                'billing_address_country',
                'billing_address_company',
            ]);
        });
    }
};
