<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('signup')->after('phone');
        });

        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
        });

        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->unique(['phone', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->dropUnique(['phone', 'purpose']);
        });

        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });

        Schema::table('phone_verification_codes', function (Blueprint $table): void {
            $table->unique(['phone']);
        });
    }
};
