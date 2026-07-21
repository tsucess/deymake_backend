<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hashtag_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->string('tag', 60);
            $table->string('display_tag', 60);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('bucket_date');
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();

            $table->unique(['tag', 'category_id', 'bucket_date'], 'hdc_tag_category_date_unique');
            $table->index(['bucket_date', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hashtag_daily_counts');
    }
};
