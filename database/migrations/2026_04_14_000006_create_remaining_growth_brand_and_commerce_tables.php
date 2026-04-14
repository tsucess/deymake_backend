<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $shouldAddVerificationStatus = ! Schema::hasColumn('users', 'creator_verification_status');
        $shouldAddVerifiedAt = ! Schema::hasColumn('users', 'creator_verified_at');
        $shouldAddVerificationNotes = ! Schema::hasColumn('users', 'creator_verification_notes');

        if ($shouldAddVerificationStatus || $shouldAddVerifiedAt || $shouldAddVerificationNotes) {
            Schema::table('users', function (Blueprint $table) use ($shouldAddVerificationStatus, $shouldAddVerifiedAt, $shouldAddVerificationNotes): void {
                if ($shouldAddVerificationStatus) {
                    $table->string('creator_verification_status', 30)->default('unsubmitted')->after('email_verified_at')->index();
                }

                if ($shouldAddVerifiedAt) {
                    $table->timestamp('creator_verified_at')->nullable()->after('creator_verification_status');
                }

                if ($shouldAddVerificationNotes) {
                    $table->text('creator_verification_notes')->nullable()->after('creator_verified_at');
                }
            });
        }

        $this->createTableIfMissing('creator_verification_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->string('legal_name');
            $table->string('country', 120);
            $table->string('document_type', 50);
            $table->string('document_url');
            $table->text('about')->nullable();
            $table->json('social_links')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        $this->createTableIfMissing('fan_tips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fan_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('posted')->index();
            $table->string('message', 280)->nullable();
            $table->boolean('is_private')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('tipped_at')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'status']);
            $table->index(['fan_id', 'status']);
        });

        $this->createTableIfMissing('revenue_share_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('source_type', 40)->default('general')->index();
            $table->unsignedTinyInteger('share_percentage');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['recipient_id', 'status']);
        });

        $this->createTableIfMissing('revenue_share_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revenue_share_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('gross_amount');
            $table->unsignedInteger('shared_amount');
            $table->string('currency', 3)->default('NGN');
            $table->unsignedTinyInteger('share_percentage');
            $table->text('notes')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('brand_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('objective', 80)->default('awareness');
            $table->string('status', 20)->default('draft')->index();
            $table->text('summary')->nullable();
            $table->unsignedInteger('budget_amount')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('min_subscribers')->default(0);
            $table->json('target_categories')->nullable();
            $table->json('target_locations')->nullable();
            $table->json('deliverables')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('sponsorship_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('brand_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('brief')->nullable();
            $table->unsignedInteger('fee_amount')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('pending')->index();
            $table->json('deliverables')->nullable();
            $table->timestamp('proposed_publish_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'status']);
            $table->index(['recipient_id', 'status']);
        });

        $this->createTableIfMissing('academy_courses', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('published')->index();
            $table->string('difficulty', 20)->default('beginner');
            $table->string('thumbnail_url')->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('estimated_minutes')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('academy_lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->longText('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->string('status', 20)->default('published')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('academy_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academy_course_id')->constrained()->cascadeOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'academy_course_id']);
        });

        $this->createTableIfMissing('academy_lesson_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academy_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academy_lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['academy_enrollment_id', 'academy_lesson_id']);
        });

        $this->createTableIfMissing('ai_editing_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->foreignId('source_upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->string('title')->nullable();
            $table->json('operations')->nullable();
            $table->json('output')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('offline_upload_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->string('client_reference');
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_reference']);
        });

        $this->createTableIfMissing('merch_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active')->index();
            $table->string('sku', 120)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_amount')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->unsignedInteger('inventory_count')->default(0);
            $table->json('images')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'status']);
        });

        $this->createTableIfMissing('merch_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merch_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_amount');
            $table->unsignedInteger('total_amount');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('paid')->index();
            $table->json('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'status']);
            $table->index(['buyer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merch_orders');
        Schema::dropIfExists('merch_products');
        Schema::dropIfExists('offline_upload_items');
        Schema::dropIfExists('ai_editing_projects');
        Schema::dropIfExists('academy_lesson_completions');
        Schema::dropIfExists('academy_enrollments');
        Schema::dropIfExists('academy_lessons');
        Schema::dropIfExists('academy_courses');
        Schema::dropIfExists('sponsorship_proposals');
        Schema::dropIfExists('brand_campaigns');
        Schema::dropIfExists('revenue_share_settlements');
        Schema::dropIfExists('revenue_share_agreements');
        Schema::dropIfExists('fan_tips');
        Schema::dropIfExists('creator_verification_requests');

        $columnsToDrop = array_values(array_filter([
            Schema::hasColumn('users', 'creator_verification_status') ? 'creator_verification_status' : null,
            Schema::hasColumn('users', 'creator_verified_at') ? 'creator_verified_at' : null,
            Schema::hasColumn('users', 'creator_verification_notes') ? 'creator_verification_notes' : null,
        ]));

        if ($columnsToDrop !== []) {
            Schema::table('users', function (Blueprint $table) use ($columnsToDrop): void {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    private function createTableIfMissing(string $tableName, \Closure $callback): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, $callback);
        }
    }
};