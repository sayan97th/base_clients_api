<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // True when the client chose "Skip for now" on a Pay Later / Request
            // Invoice checkout. Carried on the invoice (which lives from checkout
            // until the client eventually pays it) so that payment — whenever it
            // happens — still parks the resulting order(s) in `pending_details`
            // instead of `new_request`, matching the immediate card-payment path.
            $table->boolean('details_deferred')->default(false)->after('session_title');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('details_deferred');
        });
    }
};
