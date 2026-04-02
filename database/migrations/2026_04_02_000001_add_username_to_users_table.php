<?php

use App\Support\Username;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 30)->nullable()->after('name');
        });

        DB::table('users')
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->get()
            ->each(function (object $user): void {
                $fallback = Str::before((string) $user->email, '@');
                $username = Username::unique(
                    (string) ($user->name ?: $fallback),
                    static fn (string $candidate): bool => DB::table('users')
                        ->where('username', $candidate)
                        ->where('id', '!=', $user->id)
                        ->exists(),
                    $fallback !== '' ? $fallback : 'user',
                );

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['username' => $username]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};