<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->string('category')->nullable()->after('summary');
            $table->index('category');
        });

        Schema::table('challenge_submissions', function (Blueprint $table): void {
            $table->boolean('is_winner')->default(false)->after('status');
            $table->unsignedInteger('winner_rank')->nullable()->after('is_winner');
            $table->text('review_notes')->nullable()->after('winner_rank');
            $table->foreignId('reviewed_by')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();

            $table->index(['challenge_id', 'is_winner']);
        });
    }

    public function down(): void
    {
        Schema::table('challenge_submissions', function (Blueprint $table): void {
            $table->dropIndex(['challenge_id', 'is_winner']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['is_winner', 'winner_rank', 'review_notes']);
        });

        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
