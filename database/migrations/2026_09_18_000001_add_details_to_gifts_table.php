<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gifts', function (Blueprint $table): void {
            if (! Schema::hasColumn('gifts', 'description')) {
                $table->string('description', 255)->nullable()->after('image_url');
            }
            if (! Schema::hasColumn('gifts', 'animation_url')) {
                $table->string('animation_url', 2048)->nullable()->after('description');
            }
            if (! Schema::hasColumn('gifts', 'rarity')) {
                $table->string('rarity', 20)->nullable()->after('animation_url');
            }
            if (! Schema::hasColumn('gifts', 'launch_at')) {
                $table->timestamp('launch_at')->nullable()->after('rarity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gifts', function (Blueprint $table): void {
            foreach (['description', 'animation_url', 'rarity', 'launch_at'] as $column) {
                if (Schema::hasColumn('gifts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
