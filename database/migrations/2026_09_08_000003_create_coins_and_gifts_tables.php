<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coin_packages')) {
            Schema::create('coin_packages', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->unsignedInteger('coins');
                $table->unsignedInteger('bonus_coins')->default(0);
                $table->unsignedInteger('price_amount');
                $table->string('currency', 3)->default('NGN');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('coin_purchases')) {
            Schema::create('coin_purchases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('coin_package_id')->nullable()->constrained('coin_packages')->nullOnDelete();
                $table->unsignedInteger('coins');
                $table->unsignedInteger('amount');
                $table->string('currency', 3)->default('NGN');
                $table->string('status', 20)->default('completed');
                $table->string('payment_provider')->nullable();
                $table->string('payment_reference')->nullable();
                $table->boolean('is_flagged')->default(false);
                $table->string('flag_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('purchased_at')->nullable();
                $table->timestamps();
                $table->index('status');
                $table->index('is_flagged');
                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('gifts')) {
            Schema::create('gifts', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('icon')->nullable();
                $table->string('image_url')->nullable();
                $table->unsignedInteger('coin_cost');
                $table->unsignedInteger('price_amount')->default(0);
                $table->string('currency', 3)->default('NGN');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('gift_transactions')) {
            Schema::create('gift_transactions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('gift_id')->nullable()->constrained('gifts')->nullOnDelete();
                $table->string('gift_name');
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('video_id')->nullable()->constrained('videos')->nullOnDelete();
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedInteger('coin_amount');
                $table->unsignedInteger('creator_earnings')->default(0);
                $table->string('currency', 3)->default('NGN');
                $table->string('status', 20)->default('completed');
                $table->boolean('is_flagged')->default(false);
                $table->string('flag_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('refunded_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index('status');
                $table->index('is_flagged');
                $table->index(['recipient_id', 'status']);
                $table->index('sender_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_transactions');
        Schema::dropIfExists('gifts');
        Schema::dropIfExists('coin_purchases');
        Schema::dropIfExists('coin_packages');
    }
};
