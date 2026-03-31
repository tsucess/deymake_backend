<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->json('payload');
            $table->timestamps();

            $table->index(['video_id', 'recipient_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_signals');
    }
};