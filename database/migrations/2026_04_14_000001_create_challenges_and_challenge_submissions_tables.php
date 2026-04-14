<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('banner_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->json('rules')->nullable();
            $table->json('prizes')->nullable();
            $table->json('requirements')->nullable();
            $table->json('judging_criteria')->nullable();
            $table->timestamp('submission_starts_at');
            $table->timestamp('submission_ends_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('max_submissions_per_user')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_featured']);
            $table->index('submission_starts_at');
            $table->index('submission_ends_at');
        });

        Schema::create('challenge_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->text('description')->nullable();
            $table->string('media_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('external_url')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['challenge_id', 'status']);
            $table->index(['challenge_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_submissions');
        Schema::dropIfExists('challenges');
    }
};