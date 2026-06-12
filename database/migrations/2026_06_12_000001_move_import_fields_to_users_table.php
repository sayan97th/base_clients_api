<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'company')) {
                $table->string('company')->nullable()->after('last_name');
            }
            if (!Schema::hasColumn('users', 'google_studio_link')) {
                $table->string('google_studio_link')->nullable();
            }
            if (!Schema::hasColumn('users', 'referrer_id')) {
                $table->string('referrer_id')->nullable();
            }
            if (!Schema::hasColumn('users', 'note')) {
                $table->text('note')->nullable();
            }
        });

        if (Schema::hasTable('user_import_metadata')) {
            DB::table('user_import_metadata')->orderBy('id')->each(function ($meta) {
                DB::table('users')
                    ->where('id', $meta->user_id)
                    ->update([
                        'google_studio_link' => $meta->google_studio_link,
                        'referrer_id'        => $meta->referrer_id,
                        'note'               => $meta->note,
                    ]);
            });

            Schema::dropIfExists('user_import_metadata');
        }
    }

    public function down(): void
    {
        Schema::create('user_import_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('legacy_id')->nullable();
            $table->string('google_studio_link')->nullable();
            $table->string('referrer_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                ['company', 'google_studio_link', 'referrer_id', 'note'],
                fn (string $col) => Schema::hasColumn('users', $col)
            );

            if (!empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
