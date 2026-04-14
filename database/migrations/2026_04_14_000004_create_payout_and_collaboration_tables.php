<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 40)->default('bank_transfer');
            $table->string('account_name');
            $table->string('account_reference', 120);
            $table->string('account_mask', 40)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_code', 40)->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->json('metadata')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained('payout_accounts')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('amount')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('requested');
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('external_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('collaboration_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_video_id')->constrained('videos')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('type', 20)->default('duet');
            $table->string('status', 20)->default('pending');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['invitee_id', 'status']);
            $table->index(['inviter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_invites');
        Schema::dropIfExists('payout_requests');
        Schema::dropIfExists('payout_accounts');
    }
};