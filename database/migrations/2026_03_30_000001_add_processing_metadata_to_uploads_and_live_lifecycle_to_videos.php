<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table): void {
            $table->string('processing_status', 32)->default('completed')->after('size');
            $table->unsignedInteger('width')->nullable()->after('processing_status');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->decimal('duration', 10, 2)->nullable()->after('height');
            $table->string('processed_url')->nullable()->after('duration');
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->timestamp('live_started_at')->nullable()->after('is_live');
            $table->timestamp('live_ended_at')->nullable()->after('live_started_at');
            $table->timestamp('live_notified_at')->nullable()->after('live_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['live_started_at', 'live_ended_at', 'live_notified_at']);
        });

        Schema::table('uploads', function (Blueprint $table): void {
            $table->dropColumn(['processing_status', 'width', 'height', 'duration', 'processed_url']);
        });
    }
};