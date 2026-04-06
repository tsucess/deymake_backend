<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_presence_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_key', 120);
            $table->string('role', 32)->default('audience');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'session_key']);
            $table->index(['video_id', 'left_at', 'last_seen_at']);
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->unsignedInteger('live_comments_count')->default(0)->after('shares_count');
            $table->unsignedInteger('live_peak_viewers_count')->default(0)->after('live_comments_count');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['live_comments_count', 'live_peak_viewers_count']);
        });

        Schema::dropIfExists('live_presence_sessions');
    }
};