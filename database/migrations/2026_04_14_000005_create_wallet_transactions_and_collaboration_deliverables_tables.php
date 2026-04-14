<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('payout_request_id')->nullable()->constrained('payout_requests')->nullOnDelete();
            $table->string('type', 40);
            $table->string('direction', 10);
            $table->string('status', 20)->default('posted');
            $table->unsignedInteger('amount')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('collaboration_deliverables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collaboration_invite_id')->constrained('collaboration_invites')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('draft_video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('brief')->nullable();
            $table->text('feedback')->nullable();
            $table->string('status', 20)->default('drafting');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['collaboration_invite_id', 'status']);
            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_deliverables');
        Schema::dropIfExists('wallet_transactions');
    }
};