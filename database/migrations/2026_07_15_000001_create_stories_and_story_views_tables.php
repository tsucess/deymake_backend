<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('image');
            $table->string('media_url');
            $table->string('thumbnail_url')->nullable();
            $table->text('caption')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('story_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['story_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('stories');
    }
};
