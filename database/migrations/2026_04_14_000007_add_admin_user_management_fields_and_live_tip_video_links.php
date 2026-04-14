<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'account_status')) {
                $table->string('account_status', 20)->default('active')->index()->after('is_admin');
            }

            if (! Schema::hasColumn('users', 'account_status_notes')) {
                $table->text('account_status_notes')->nullable()->after('account_status');
            }

            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('account_status_notes');
            }

            if (! Schema::hasColumn('users', 'suspended_by')) {
                $table->foreignId('suspended_by')->nullable()->after('suspended_at')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('fan_tips', function (Blueprint $table): void {
            if (! Schema::hasColumn('fan_tips', 'video_id')) {
                $table->foreignId('video_id')->nullable()->after('fan_id')->constrained('videos')->nullOnDelete();
                $table->index(['video_id', 'status']);
            }
        });

        if (Schema::hasColumn('users', 'account_status')) {
            \Illuminate\Support\Facades\DB::table('users')
                ->whereNull('account_status')
                ->update(['account_status' => 'active']);
        }
    }

    public function down(): void
    {
        Schema::table('fan_tips', function (Blueprint $table): void {
            if (Schema::hasColumn('fan_tips', 'video_id')) {
                $table->dropConstrainedForeignId('video_id');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'suspended_by')) {
                $table->dropConstrainedForeignId('suspended_by');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'account_status') ? 'account_status' : null,
                Schema::hasColumn('users', 'account_status_notes') ? 'account_status_notes' : null,
                Schema::hasColumn('users', 'suspended_at') ? 'suspended_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};