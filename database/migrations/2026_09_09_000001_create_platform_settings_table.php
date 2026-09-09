<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        DB::table('platform_settings')->insert([
            [
                'key' => 'app',
                'value' => json_encode([
                    'platform_name' => 'DeyMake',
                    'platform_logo' => null,
                    'supported_languages' => ['en', 'ig', 'yo'],
                    'registration_enabled' => true,
                    'maintenance_mode' => false,
                    'upload_limits' => [
                        'max_file_size_mb' => 200,
                        'max_files_per_upload' => 10,
                    ],
                    'live_stream_limits' => [
                        'max_duration_minutes' => 180,
                        'max_viewers' => 5000,
                    ],
                    'monetization' => [
                        'creator_commission_rate' => 10,
                        'platform_commission_rate' => 5,
                        'payout_minimum_naira' => 2000,
                    ],
                    'moderation_thresholds' => [
                        'auto_hide_risk_level' => 'high',
                        'manual_review_risk_level' => 'medium',
                    ],
                    'notification_defaults' => [
                        'push' => true,
                        'email' => true,
                        'sms' => false,
                    ],
                    'feature_flags' => [
                        'creator_verification' => true,
                        'live_streams' => true,
                        'merch_store' => true,
                    ],
                    'oauth_providers' => [
                        'google' => ['enabled' => false, 'client_id' => null],
                        'facebook' => ['enabled' => false, 'client_id' => null],
                    ],
                    'payment_providers' => [
                        'paystack' => ['enabled' => true, 'public_key' => null],
                        'flutterwave' => ['enabled' => false, 'public_key' => null],
                    ],
                ]),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
