<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnsIfMissing('merch_products', [
            'product_code' => fn (Blueprint $table) => $table->string('product_code', 120)->nullable(),
            'category' => fn (Blueprint $table) => $table->string('category', 120)->nullable(),
            'product_type' => fn (Blueprint $table) => $table->string('product_type', 120)->nullable(),
            'low_stock_threshold' => fn (Blueprint $table) => $table->unsignedInteger('low_stock_threshold')->default(0),
        ]);

        $this->addColumnsIfMissing('brand_campaigns', [
            'business_name' => fn (Blueprint $table) => $table->string('business_name')->nullable(),
            'niche' => fn (Blueprint $table) => $table->string('niche', 120)->nullable(),
            'creators_needed' => fn (Blueprint $table) => $table->unsignedInteger('creators_needed')->default(0),
            'creator_requirements' => fn (Blueprint $table) => $table->text('creator_requirements')->nullable(),
        ]);
    }

    public function down(): void
    {
        $this->dropColumnsIfPresent('merch_products', [
            'product_code',
            'category',
            'product_type',
            'low_stock_threshold',
        ]);

        $this->dropColumnsIfPresent('brand_campaigns', [
            'business_name',
            'niche',
            'creators_needed',
            'creator_requirements',
        ]);
    }

    private function addColumnsIfMissing(string $tableName, array $definitions): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $missing = array_filter(
            $definitions,
            fn ($definition, $column) => ! Schema::hasColumn($tableName, $column),
            ARRAY_FILTER_USE_BOTH
        );

        if ($missing === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($missing): void {
            foreach ($missing as $definition) {
                $definition($table);
            }
        });
    }

    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $existing = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($tableName, $column)
        ));

        if ($existing !== []) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
