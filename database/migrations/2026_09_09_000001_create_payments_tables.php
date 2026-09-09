<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table): void {
                $table->id();
                $table->string('reference')->unique();
                $table->string('provider')->default('paystack');
                $table->string('provider_reference')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('email')->nullable();
                $table->string('purpose', 40)->default('general');
                $table->string('purpose_type')->nullable();
                $table->unsignedBigInteger('purpose_id')->nullable();
                $table->unsignedBigInteger('amount');
                $table->string('currency', 3)->default('NGN');
                $table->string('status', 20)->default('pending');
                $table->string('channel')->nullable();
                $table->unsignedBigInteger('fees')->default(0);
                $table->string('authorization_url')->nullable();
                $table->string('gateway_response')->nullable();
                $table->boolean('is_flagged')->default(false);
                $table->string('flag_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->string('reconciliation_note')->nullable();
                $table->timestamps();
                $table->index('status');
                $table->index('provider');
                $table->index('purpose');
                $table->index('is_flagged');
                $table->index('provider_reference');
                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('payment_webhook_events')) {
            Schema::create('payment_webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->string('provider')->default('paystack');
                $table->string('event_type')->nullable();
                $table->string('reference')->nullable();
                $table->string('dedupe_key');
                $table->boolean('signature_valid')->default(false);
                $table->json('payload')->nullable();
                $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'dedupe_key']);
                $table->index('reference');
                $table->index('event_type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};
