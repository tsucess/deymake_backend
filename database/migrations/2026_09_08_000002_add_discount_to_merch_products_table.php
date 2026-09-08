<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('merch_products', 'discount_amount')) {
            Schema::table('merch_products', function (Blueprint $table): void {
                $table->unsignedInteger('discount_amount')->default(0)->after('price_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('merch_products', 'discount_amount')) {
            Schema::table('merch_products', function (Blueprint $table): void {
                $table->dropColumn('discount_amount');
            });
        }
    }
};
