<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring billing for admin managed SEO subscriptions.
 *
 * - Custom pricing: a subscription can follow the listed package price or a
 *   negotiated custom monthly price, optionally without any listed package.
 * - Auto renew: the client's card on file is charged every month on the
 *   billing anchor day, with an optional reminder email before each renewal.
 * - The package foreign key no longer cascades, so deleting a package never
 *   wipes a client's billing history. plan_name keeps a snapshot of the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_package_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['seo_package_id']);
        });

        Schema::table('seo_package_subscriptions', function (Blueprint $table) {
            $table->string('seo_package_id')->nullable()->change();
            $table->foreign('seo_package_id')->references('id')->on('seo_packages')->nullOnDelete();

            $table->string('plan_name')->nullable()->after('seo_package_id');
            $table->string('pricing_type', 20)->default('package')->after('plan_name');
            $table->decimal('monthly_price', 10, 2)->default(0)->after('pricing_type');
            $table->string('invoice_item_label')->nullable()->after('monthly_price');
            $table->text('invoice_item_description')->nullable()->after('invoice_item_label');

            $table->boolean('auto_renew')->default(false)->after('invoice_item_description');
            $table->uuid('payment_profile_id')->nullable()->after('auto_renew');
            $table->unsignedTinyInteger('billing_anchor_day')->nullable()->after('payment_profile_id');
            $table->date('next_billing_date')->nullable()->after('billing_anchor_day');
            $table->unsignedTinyInteger('reminder_days_before')->nullable()->default(3)->after('next_billing_date');
            $table->date('last_reminder_sent_for')->nullable()->after('reminder_days_before');
            $table->string('payment_status', 20)->default('current')->after('last_reminder_sent_for');
            $table->timestamp('auto_renew_disabled_at')->nullable()->after('payment_status');

            $table->foreign('payment_profile_id')->references('id')->on('payment_profiles')->nullOnDelete();
            $table->index(['status', 'auto_renew', 'next_billing_date'], 'seo_pkg_subs_billing_due_index');
        });

        // Existing subscriptions keep billing at the package price they were
        // activated with, and stay on manual billing until an admin turns on
        // auto renew. Nothing is charged automatically by this migration.
        DB::table('seo_package_subscriptions')
            ->leftJoin('seo_packages', 'seo_packages.id', '=', 'seo_package_subscriptions.seo_package_id')
            ->select('seo_package_subscriptions.id', 'seo_packages.name', 'seo_packages.price_per_month')
            ->orderBy('seo_package_subscriptions.id')
            ->each(function ($row) {
                DB::table('seo_package_subscriptions')->where('id', $row->id)->update([
                    'plan_name'     => $row->name,
                    'monthly_price' => (float) ($row->price_per_month ?? 0),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('seo_package_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['payment_profile_id']);
            $table->dropIndex('seo_pkg_subs_billing_due_index');
            $table->dropColumn([
                'plan_name',
                'pricing_type',
                'monthly_price',
                'invoice_item_label',
                'invoice_item_description',
                'auto_renew',
                'payment_profile_id',
                'billing_anchor_day',
                'next_billing_date',
                'reminder_days_before',
                'last_reminder_sent_for',
                'payment_status',
                'auto_renew_disabled_at',
            ]);
            $table->dropForeign(['seo_package_id']);
        });

        DB::table('seo_package_subscriptions')->whereNull('seo_package_id')->delete();

        Schema::table('seo_package_subscriptions', function (Blueprint $table) {
            $table->string('seo_package_id')->nullable(false)->change();
            $table->foreign('seo_package_id')->references('id')->on('seo_packages')->cascadeOnDelete();
        });
    }
};
