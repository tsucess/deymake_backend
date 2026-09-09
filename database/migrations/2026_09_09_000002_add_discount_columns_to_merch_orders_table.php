<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merch_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('merch_orders', 'discount_code')) {
                $table->string('discount_code')->nullable()->after('currency');
            }

            if (! Schema::hasColumn('merch_orders', 'discount_amount')) {
                $table->unsignedInteger('discount_amount')->default(0)->after('discount_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merch_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('merch_orders', 'discount_code')) {
                $table->dropColumn('discount_code');
            }

            if (Schema::hasColumn('merch_orders', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
        });
    }
};
