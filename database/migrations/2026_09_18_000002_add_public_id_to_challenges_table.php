<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PUBLIC_ID_LENGTH = 12;

    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->string('public_id', 32)->nullable()->unique();
        });

        DB::table('challenges')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($challenges): void {
                foreach ($challenges as $challenge) {
                    DB::table('challenges')
                        ->where('id', $challenge->id)
                        ->update(['public_id' => $this->generateUniquePublicId()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropUnique('challenges_public_id_unique');
            $table->dropColumn('public_id');
        });
    }

    private function generateUniquePublicId(): string
    {
        do {
            $publicId = Str::lower(Str::random(self::PUBLIC_ID_LENGTH));
        } while (DB::table('challenges')->where('public_id', $publicId)->exists());

        return $publicId;
    }
};
