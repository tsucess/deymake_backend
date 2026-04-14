<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('moderation_status', 20)->default('visible')->after('is_draft')->index();
            $table->foreignId('moderated_by')->nullable()->after('moderation_status')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
            $table->text('moderation_notes')->nullable()->after('moderated_at');
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->string('moderation_status', 20)->default('visible')->after('body')->index();
            $table->foreignId('moderated_by')->nullable()->after('moderation_status')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
            $table->text('moderation_notes')->nullable()->after('moderated_at');
        });

        Schema::create('content_moderation_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('moderatable_type');
            $table->unsignedBigInteger('moderatable_id');
            $table->string('content_type', 20);
            $table->string('source', 20)->default('ai_scan');
            $table->string('status', 20)->default('clean')->index();
            $table->unsignedInteger('ai_score')->default(0);
            $table->string('ai_risk_level', 20)->default('none')->index();
            $table->json('ai_flags')->nullable();
            $table->text('ai_summary')->nullable();
            $table->unsignedInteger('report_count')->default(0);
            $table->timestamp('last_reported_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('action_reason')->nullable();
            $table->timestamps();

            $table->unique(['moderatable_type', 'moderatable_id'], 'content_moderation_cases_unique_moderatable');
            $table->index(['content_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_moderation_cases');

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropForeign(['moderated_by']);
            $table->dropColumn(['moderation_status', 'moderated_by', 'moderated_at', 'moderation_notes']);
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropForeign(['moderated_by']);
            $table->dropColumn(['moderation_status', 'moderated_by', 'moderated_at', 'moderation_notes']);
        });
    }
};