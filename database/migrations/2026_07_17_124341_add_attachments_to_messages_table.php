<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('attachment_url', 2048)->nullable()->after('body');
            $table->string('attachment_type', 20)->nullable()->after('attachment_url');
            $table->string('attachment_name')->nullable()->after('attachment_type');
            $table->string('attachment_mime', 191)->nullable()->after('attachment_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn([
                'attachment_url',
                'attachment_type',
                'attachment_name',
                'attachment_mime',
                'attachment_size',
            ]);
        });
    }
};
