<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\CreatorVerificationUpdated;
use App\Events\ManagedUserUpdated;
use App\Events\UserAccountUpdated;
use App\Http\Requests\Admin\UpdateManagedUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\ContentModerationCaseResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\VideoReportResource;
use App\Http\Resources\VideoResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\AuditLog;
use App\Models\ContentModerationCase;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin user management controller.
 *
 * Admin actions on users: list, suspend, restore, role changes.
 *
 * Routes: under the admin users prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/Users.jsx, SuspendedAccount.jsx.
 * Related: User model, AdminUserResource.
 * See PROJECT_OVERVIEW.md §3.27 for the full data-flow map.
 */
class AdminUserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $accountStatus = trim($request->string('accountStatus')->toString());
        $role = trim($request->string('role')->toString());
        $verificationStatus = trim($request->string('verificationStatus')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $users = PaginatedJson::paginate(
            $this->userQuery()
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $searchQuery) use ($query): void {
                        $searchQuery
                            ->where('name', 'like', '%'.$query.'%')
                            ->orWhere('username', 'like', '%'.$query.'%')
                            ->orWhere('email', 'like', '%'.$query.'%');
                    });
                })
                ->when(in_array($accountStatus, ['active', 'suspended', 'banned'], true), fn (Builder $builder) => $builder->where('account_status', $accountStatus))
                ->when($role === 'admin', fn (Builder $builder) => $builder->where('is_admin', true))
                ->when($role === 'creator', fn (Builder $builder) => $builder->has('videos'))
                ->when($role === 'member', fn (Builder $builder) => $builder->where('is_admin', false)->doesntHave('videos'))
                ->when(in_array($verificationStatus, ['verified', 'approved'], true), fn (Builder $builder) => $builder->where('creator_verification_status', 'approved'))
                ->when($verificationStatus === 'pending', fn (Builder $builder) => $builder->where('creator_verification_status', 'pending'))
                ->when($verificationStatus === 'rejected', fn (Builder $builder) => $builder->where('creator_verification_status', 'rejected'))
                ->when($verificationStatus === 'unsubmitted', fn (Builder $builder) => $builder->where(fn (Builder $inner) => $inner->whereNull('creator_verification_status')->orWhere('creator_verification_status', 'unsubmitted')))
                ->when($verificationStatus === 'unverified', fn (Builder $builder) => $builder->where(fn (Builder $inner) => $inner->whereNull('creator_verification_status')->orWhere('creator_verification_status', '!=', 'approved')))
                ->when($sort === 'oldest', fn (Builder $builder) => $builder->oldest())
                ->when($sort === 'active', fn (Builder $builder) => $builder->orderByDesc('last_active_at')->latest())
                ->when(! in_array($sort, ['oldest', 'active'], true), fn (Builder $builder) => $builder->latest()),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.users_retrieved'),
            'data' => [
                'users' => PaginatedJson::items($request, $users, AdminUserResource::class),
            ],
            'meta' => [
                'users' => PaginatedJson::meta($users),
                'summary' => [
                    'totalUsers' => User::query()->count(),
                    'adminUsers' => User::query()->where('is_admin', true)->count(),
                    'suspendedUsers' => User::query()->where('account_status', 'suspended')->count(),
                    'bannedUsers' => User::query()->where('account_status', 'banned')->count(),
                    'creatorUsers' => User::query()->has('videos')->count(),
                    'verifiedUsers' => User::query()->where('creator_verification_status', 'approved')->count(),
                    'pendingVerificationUsers' => User::query()->where('creator_verification_status', 'pending')->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $user->loadCount($this->managementCounts());

        return response()->json([
            'message' => __('messages.admin.user_retrieved'),
            'data' => [
                'user' => new AdminUserResource($user),
                'wallet' => $this->walletSummary($user),
                'membership' => $this->membershipSummary($user),
                'moderation' => $this->moderationSummary($user),
            ],
        ]);
    }

    public function update(UpdateManagedUserRequest $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $currentAdmin = $request->user();
        $isSelf = $currentAdmin->is($user);

        $previousStatus = $user->accountStatus();
        $previousIsAdmin = $user->isAdmin();

        $nextStatus = $validated['accountStatus'] ?? $previousStatus;
        $nextIsAdmin = array_key_exists('isAdmin', $validated) ? (bool) $validated['isAdmin'] : $previousIsAdmin;
        $verify = (bool) ($validated['verify'] ?? false);
        $resetVerification = (bool) ($validated['resetVerification'] ?? false);
        $previousVerificationStatus = $user->creator_verification_status ?: 'unsubmitted';

        $willBlock = in_array($nextStatus, ['suspended', 'banned'], true);
        $willDemote = $previousIsAdmin && ! $nextIsAdmin;

        abort_if(
            $isSelf && ($willBlock || $willDemote),
            422,
            __('messages.admin.user_self_protection')
        );

        abort_if(
            $previousIsAdmin && ($willBlock || $willDemote) && ! $this->hasOtherActiveAdmin($user),
            422,
            __('messages.admin.user_last_admin_protection')
        );

        $user->forceFill([
            'is_admin' => $nextIsAdmin,
            'account_status' => $nextStatus,
            'account_status_notes' => $validated['accountStatusNotes'] ?? $user->account_status_notes,
            'suspended_at' => $nextStatus === 'suspended' ? now() : null,
            'suspended_by' => $nextStatus === 'suspended' ? $currentAdmin->id : null,
            'banned_at' => $nextStatus === 'banned' ? now() : null,
            'banned_by' => $nextStatus === 'banned' ? $currentAdmin->id : null,
            'is_online' => $willBlock ? false : $user->is_online,
        ]);

        if ($verify) {
            $user->forceFill([
                'creator_verification_status' => 'approved',
                'creator_verified_at' => now(),
            ]);
        } elseif ($resetVerification) {
            $user->forceFill([
                'creator_verification_status' => 'unsubmitted',
                'creator_verified_at' => null,
                'creator_verification_notes' => null,
            ]);
        }

        $user->save();

        if (($validated['clearSessions'] ?? false) || $willBlock) {
            $user->tokens()->delete();
        }

        $this->recordAuditTrail($request, $currentAdmin, $user, [
            'previousStatus' => $previousStatus,
            'nextStatus' => $nextStatus,
            'previousIsAdmin' => $previousIsAdmin,
            'nextIsAdmin' => $nextIsAdmin,
            'previousVerificationStatus' => $previousVerificationStatus,
            'nextVerificationStatus' => $user->creator_verification_status ?: 'unsubmitted',
            'verify' => $verify,
            'resetVerification' => $resetVerification,
            'notes' => $validated['accountStatusNotes'] ?? null,
        ]);

        $user->loadCount($this->managementCounts());

        ManagedUserUpdated::dispatch($user);
        UserAccountUpdated::dispatch($user);
        CreatorVerificationUpdated::dispatch($user);

        return response()->json([
            'message' => __('messages.admin.user_updated'),
            'data' => [
                'user' => new AdminUserResource($user),
            ],
        ]);
    }

    public function videos(Request $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $videos = PaginatedJson::paginate(
            $user->videos()
                ->with([
                    'user' => fn ($query) => $query->withProfileAggregates($request->user()),
                    'upload',
                ])
                ->latest(),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.user_videos_retrieved'),
            'data' => [
                'videos' => PaginatedJson::items($request, $videos, VideoResource::class),
            ],
            'meta' => [
                'videos' => PaginatedJson::meta($videos),
            ],
        ]);
    }

    public function transactions(Request $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $transactions = PaginatedJson::paginate(
            $user->walletTransactions()->latest('occurred_at')->latest(),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.user_transactions_retrieved'),
            'data' => [
                'transactions' => PaginatedJson::items($request, $transactions, WalletTransactionResource::class),
            ],
            'meta' => [
                'transactions' => PaginatedJson::meta($transactions),
            ],
        ]);
    }

    public function reports(Request $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $reports = PaginatedJson::paginate(
            VideoReport::query()
                ->whereHas('video', fn (Builder $builder) => $builder->where('user_id', $user->id))
                ->with(['user', 'reviewer', 'video.user'])
                ->latest(),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.user_reports_retrieved'),
            'data' => [
                'reports' => PaginatedJson::items($request, $reports, VideoReportResource::class),
            ],
            'meta' => [
                'reports' => PaginatedJson::meta($reports),
            ],
        ]);
    }

    public function activity(Request $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $videos = $user->videos()->latest()->limit(20)->get()->map(fn (Video $video) => [
            'type' => 'video',
            'title' => $video->title ?: $video->caption,
            'description' => __('messages.admin.activity_video_uploaded'),
            'at' => $video->created_at?->toISOString(),
        ]);

        $reports = $user->videoReports()->latest()->limit(20)->get()->map(fn (VideoReport $report) => [
            'type' => 'report',
            'title' => $report->reason,
            'description' => __('messages.admin.activity_report_filed'),
            'at' => $report->created_at?->toISOString(),
        ]);

        $submissions = $user->challengeSubmissions()->with('challenge')->latest()->limit(20)->get()->map(fn ($submission) => [
            'type' => 'challenge',
            'title' => $submission->title ?: $submission->challenge?->title,
            'description' => __('messages.admin.activity_challenge_submitted'),
            'at' => $submission->created_at?->toISOString(),
        ]);

        $payouts = $user->payoutRequests()->latest()->limit(20)->get()->map(fn (PayoutRequest $payout) => [
            'type' => 'payout',
            'title' => __('messages.admin.activity_payout_requested'),
            'description' => __('messages.admin.activity_payout_requested'),
            'at' => $payout->created_at?->toISOString(),
        ]);

        $activity = collect()
            ->concat($videos)
            ->concat($reports)
            ->concat($submissions)
            ->concat($payouts)
            ->filter(fn (array $item) => $item['at'] !== null)
            ->sortByDesc('at')
            ->take(20)
            ->values()
            ->all();

        return response()->json([
            'message' => __('messages.admin.user_activity_retrieved'),
            'data' => [
                'activity' => $activity,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function walletSummary(User $user): array
    {
        $credited = (int) $user->walletTransactions()->where('direction', 'credit')->where('status', 'posted')->sum('amount');
        $debited = (int) $user->walletTransactions()->where('direction', 'debit')->where('status', 'posted')->sum('amount');
        $pending = (int) $user->walletTransactions()->where('status', 'pending')->sum('amount');

        $recent = $user->walletTransactions()->latest('occurred_at')->latest()->limit(5)->get();

        return [
            'currency' => $user->walletTransactions()->value('currency') ?: 'NGN',
            'balance' => $credited - $debited,
            'credited' => $credited,
            'debited' => $debited,
            'pending' => $pending,
            'transactionsCount' => $user->walletTransactions()->count(),
            'recent' => WalletTransactionResource::collection($recent),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipSummary(User $user): array
    {
        $recent = $user->memberships()->with(['plan', 'creator'])->latest()->limit(5)->get();

        return [
            'currency' => $user->managedMemberships()->value('currency') ?: ($user->memberships()->value('currency') ?: 'USD'),
            'asMember' => [
                'active' => $user->memberships()->where('status', 'active')->count(),
                'total' => $user->memberships()->count(),
            ],
            'asCreator' => [
                'active' => $user->managedMemberships()->where('status', 'active')->count(),
                'total' => $user->managedMemberships()->count(),
                'monthlyRevenue' => (int) $user->managedMemberships()->where('status', 'active')->where('billing_period', 'monthly')->sum('price_amount'),
            ],
            'recent' => MembershipResource::collection($recent),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function moderationSummary(User $user): array
    {
        $videoIds = $user->videos()->pluck('id');
        $morphType = (new Video)->getMorphClass();

        $base = fn () => ContentModerationCase::query()
            ->where('moderatable_type', $morphType)
            ->whereIn('moderatable_id', $videoIds);

        $recent = $base()->with(['moderatable', 'reviewer'])->latest()->limit(10)->get();

        return [
            'total' => $base()->count(),
            'flagged' => $base()->where('status', 'flagged')->count(),
            'removed' => $base()->where('status', 'removed')->count(),
            'recent' => ContentModerationCaseResource::collection($recent),
        ];
    }

    private function hasOtherActiveAdmin(User $user): bool
    {
        return User::query()
            ->where('is_admin', true)
            ->where('id', '!=', $user->id)
            ->whereNotIn('account_status', ['suspended', 'banned'])
            ->whereNull('suspended_at')
            ->whereNull('banned_at')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordAuditTrail(Request $request, User $admin, User $user, array $context): void
    {
        $entries = [];

        if ($context['previousStatus'] !== $context['nextStatus']) {
            $entries[$this->statusAction($context['previousStatus'], $context['nextStatus'])] = [
                'from' => $context['previousStatus'],
                'to' => $context['nextStatus'],
                'notes' => $context['notes'],
            ];
        }

        if ($context['previousIsAdmin'] !== $context['nextIsAdmin']) {
            $entries[$context['nextIsAdmin'] ? 'admin.user_promoted' : 'admin.user_demoted'] = [
                'from' => $context['previousIsAdmin'],
                'to' => $context['nextIsAdmin'],
            ];
        }

        if ($context['resetVerification']) {
            $entries['admin.user_verification_reset'] = [
                'to' => 'unsubmitted',
            ];
        }

        foreach ($entries as $action => $metadata) {
            AuditLog::create([
                'user_id' => $admin->id,
                'action' => $action,
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => $user->id,
                'metadata' => $metadata,
                'ip_address' => $request->ip(),
            ]);
        }
    }

    private function statusAction(string $previous, string $next): string
    {
        return match ($next) {
            'suspended' => 'admin.user_suspended',
            'banned' => 'admin.user_banned',
            default => $previous === 'banned' ? 'admin.user_unbanned' : 'admin.user_unsuspended',
        };
    }

    /**
     * @return array<int, string|array<string, \Closure>>
     */
    private function managementCounts(): array
    {
        return [
            'videos as videos_count' => fn (Builder $builder) => $builder->where('is_draft', false)->where('moderation_status', 'visible'),
            'subscribers',
            'subscribedCreators as following_count',
            'videoReports',
            'receivedVideoReports',
            'challengeSubmissions',
            'videos as published_videos_count' => fn (Builder $builder) => $builder->where('is_draft', false),
            'videos as live_videos_count' => fn (Builder $builder) => $builder->where('is_live', true),
        ];
    }

    private function userQuery(): Builder
    {
        return User::query()->withCount($this->managementCounts());
    }
}
