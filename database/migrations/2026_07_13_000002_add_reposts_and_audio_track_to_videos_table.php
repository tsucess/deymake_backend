<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->unsignedBigInteger('reposts_count')->default(0)->after('shares_count');
            $table->string('audio_track_title')->nullable()->after('reposts_count');
            $table->string('audio_track_artist')->nullable()->after('audio_track_title');
            $table->string('audio_track_cover_url')->nullable()->after('audio_track_artist');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn([
                'reposts_count',
                'audio_track_title',
                'audio_track_artist',
                'audio_track_cover_url',
            ]);
        });
    }
};
