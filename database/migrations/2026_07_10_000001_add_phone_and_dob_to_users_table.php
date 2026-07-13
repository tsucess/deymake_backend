<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('phone')->nullable()->unique()->after('email');
            $table->string('country_code', 8)->nullable()->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('country_code');
            $table->date('date_of_birth')->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'country_code', 'phone_verified_at', 'date_of_birth']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
