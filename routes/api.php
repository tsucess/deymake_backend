<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminPayoutController;
use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\ChallengeController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\CollaborationDeliverableController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CreatorAnalyticsController;
use App\Http\Controllers\Api\ContentModerationController;
use App\Http\Controllers\Api\DeveloperController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\InfoController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\MonetizationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\VideoInteractionController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Middleware\RecordUserActivity;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware(SetLocale::class)->group(function (): void {
        Route::get('/health', HealthController::class);

        Route::prefix('auth')->group(function (): void {
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);
            Route::post('/verify-email-code', [AuthController::class, 'verifyEmailCode']);
            Route::post('/resend-verification-code', [AuthController::class, 'resendVerificationCode']);
            Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
            Route::post('/reset-password', [AuthController::class, 'resetPassword']);
            Route::get('/oauth/{provider}/redirect', [AuthController::class, 'oauthRedirect']);
            Route::get('/oauth/{provider}/callback', [AuthController::class, 'oauthCallback']);
        });

        Route::post('/waitlist', [WaitlistController::class, 'store']);

        Route::get('/home', [HomeController::class, 'index']);
        Route::get('/categories', [HomeController::class, 'categories']);

        Route::get('/videos/trending', [VideoController::class, 'trending']);
        Route::get('/videos/live', [VideoController::class, 'live']);
        Route::get('/videos', [VideoController::class, 'index']);
        Route::get('/videos/{video}', [VideoController::class, 'show']);
        Route::get('/videos/{video}/related', [VideoController::class, 'related']);
        Route::post('/videos/{video}/view', [VideoController::class, 'recordView']);
        Route::post('/videos/{video}/share', [VideoController::class, 'share']);
        Route::get('/videos/{video}/comments', [CommentController::class, 'index']);

        Route::get('/comments/{comment}/replies', [CommentController::class, 'replies']);

        Route::get('/users/search', [UserController::class, 'search']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::get('/users/{user}/posts', [UserController::class, 'posts']);
        Route::get('/users/{user}/plans', [MembershipController::class, 'creatorPlans']);

        Route::get('/leaderboard', [LeaderboardController::class, 'index']);

        Route::get('/challenges', [ChallengeController::class, 'index']);
        Route::get('/challenges/{challenge}', [ChallengeController::class, 'show']);
        Route::get('/challenges/{challenge}/submissions', [ChallengeController::class, 'submissions']);

        Route::get('/search/suggestions', [SearchController::class, 'suggestions']);
        Route::get('/search/videos', [SearchController::class, 'videos']);
        Route::get('/search/creators', [SearchController::class, 'creators']);
        Route::get('/search/categories', [SearchController::class, 'categories']);
        Route::get('/search', [SearchController::class, 'global']);

        Route::get('/help', [InfoController::class, 'help']);
        Route::get('/legal/privacy', [InfoController::class, 'privacy']);
        Route::get('/legal/terms', [InfoController::class, 'terms']);
    });

    Route::middleware(['auth:sanctum', RecordUserActivity::class, SetLocale::class])->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });

        Route::post('/uploads', [UploadController::class, 'store']);
        Route::post('/uploads/presign', [UploadController::class, 'presign']);

        Route::post('/videos', [VideoController::class, 'store']);
        Route::patch('/videos/{video}', [VideoController::class, 'update']);
        Route::post('/videos/{video}/publish', [VideoController::class, 'publish']);
        Route::get('/videos/{video}/live/session', [VideoController::class, 'liveSession']);
        Route::post('/videos/{video}/live/start', [VideoController::class, 'startLive']);
        Route::post('/videos/{video}/live/stop', [VideoController::class, 'stopLive']);
        Route::post('/videos/{video}/live/like', [VideoInteractionController::class, 'liveLike']);
        Route::get('/videos/{video}/live/engagements', [VideoController::class, 'liveEngagements']);
        Route::get('/videos/{video}/live/audience', [VideoController::class, 'liveAudience']);
        Route::post('/videos/{video}/live/presence', [VideoController::class, 'recordPresence']);
        Route::post('/videos/{video}/live/presence/leave', [VideoController::class, 'leavePresence']);
        Route::post('/videos/{video}/live/signals', [VideoController::class, 'sendSignal']);
        Route::get('/videos/{video}/live/signals', [VideoController::class, 'getSignals']);
        Route::post('/videos/{video}/report', [VideoController::class, 'report']);

        Route::post('/videos/{video}/like', [VideoInteractionController::class, 'like']);
        Route::delete('/videos/{video}/like', [VideoInteractionController::class, 'unlike']);
        Route::post('/videos/{video}/dislike', [VideoInteractionController::class, 'dislike']);
        Route::delete('/videos/{video}/dislike', [VideoInteractionController::class, 'undislike']);
        Route::post('/videos/{video}/save', [VideoInteractionController::class, 'save']);
        Route::delete('/videos/{video}/save', [VideoInteractionController::class, 'unsave']);

        Route::post('/creators/{creator}/subscribe', [VideoInteractionController::class, 'subscribe']);
        Route::delete('/creators/{creator}/subscribe', [VideoInteractionController::class, 'unsubscribe']);

        Route::post('/videos/{video}/comments', [CommentController::class, 'store']);
        Route::post('/comments/{comment}/replies', [CommentController::class, 'storeReply']);
        Route::patch('/comments/{comment}', [CommentController::class, 'update']);
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
        Route::post('/comments/{comment}/like', [CommentController::class, 'like']);
        Route::delete('/comments/{comment}/like', [CommentController::class, 'unlike']);
        Route::post('/comments/{comment}/dislike', [CommentController::class, 'dislike']);
        Route::delete('/comments/{comment}/dislike', [CommentController::class, 'undislike']);

        Route::prefix('me')->group(function (): void {
            Route::get('/profile', [ProfileController::class, 'show']);
            Route::patch('/profile', [ProfileController::class, 'update']);
            Route::get('/challenges', [ChallengeController::class, 'myChallenges']);
            Route::get('/challenge-submissions', [ChallengeController::class, 'mySubmissions']);
            Route::get('/subscribers', [ProfileController::class, 'subscribers']);
            Route::get('/analytics', [CreatorAnalyticsController::class, 'dashboard']);
            Route::get('/analytics/videos/{video}', [CreatorAnalyticsController::class, 'showVideo']);
            Route::get('/posts', [ProfileController::class, 'posts']);
            Route::get('/liked', [ProfileController::class, 'liked']);
            Route::get('/saved', [ProfileController::class, 'saved']);
            Route::get('/drafts', [ProfileController::class, 'drafts']);
            Route::get('/preferences', [ProfileController::class, 'preferences']);
            Route::patch('/preferences', [ProfileController::class, 'updatePreferences']);
        });

        Route::prefix('developer')->group(function (): void {
            Route::get('/', [DeveloperController::class, 'overview']);
            Route::post('/api-keys', [DeveloperController::class, 'storeApiKey']);
            Route::delete('/api-keys/{token}', [DeveloperController::class, 'destroyApiKey']);
            Route::post('/webhooks', [DeveloperController::class, 'storeWebhook']);
            Route::patch('/webhooks/{webhook}', [DeveloperController::class, 'updateWebhook']);
            Route::post('/webhooks/{webhook}/rotate-secret', [DeveloperController::class, 'rotateWebhookSecret']);
            Route::delete('/webhooks/{webhook}', [DeveloperController::class, 'destroyWebhook']);
        });

        Route::prefix('memberships')->group(function (): void {
            Route::get('/creator', [MembershipController::class, 'creatorDashboard']);
            Route::get('/mine', [MembershipController::class, 'myMemberships']);
            Route::post('/plans', [MembershipController::class, 'storePlan']);
            Route::patch('/plans/{plan}', [MembershipController::class, 'updatePlan']);
            Route::delete('/plans/{plan}', [MembershipController::class, 'destroyPlan']);
            Route::post('/plans/{plan}/subscribe', [MembershipController::class, 'subscribe']);
            Route::post('/{membership}/cancel', [MembershipController::class, 'cancel']);
        });

        Route::prefix('monetization')->group(function (): void {
            Route::get('/summary', [MonetizationController::class, 'summary']);
            Route::get('/payout-account', [MonetizationController::class, 'payoutAccount']);
            Route::put('/payout-account', [MonetizationController::class, 'upsertPayoutAccount']);
            Route::get('/payouts', [MonetizationController::class, 'payouts']);
            Route::post('/payouts', [MonetizationController::class, 'requestPayout']);
            Route::get('/transactions', [MonetizationController::class, 'transactions']);
        });

        Route::prefix('collaborations')->group(function (): void {
            Route::get('/invites', [CollaborationController::class, 'index']);
            Route::post('/invites', [CollaborationController::class, 'store']);
            Route::patch('/invites/{collaborationInvite}', [CollaborationController::class, 'update']);
            Route::get('/invites/{collaborationInvite}/deliverables', [CollaborationDeliverableController::class, 'index']);
            Route::post('/invites/{collaborationInvite}/deliverables', [CollaborationDeliverableController::class, 'store']);
            Route::patch('/deliverables/{collaborationDeliverable}', [CollaborationDeliverableController::class, 'update']);
        });

        Route::post('/challenges', [ChallengeController::class, 'store']);
        Route::patch('/challenges/{challenge}', [ChallengeController::class, 'update']);
        Route::delete('/challenges/{challenge}', [ChallengeController::class, 'destroy']);
        Route::post('/challenges/{challenge}/publish', [ChallengeController::class, 'publish']);
        Route::post('/challenges/{challenge}/submissions', [ChallengeController::class, 'storeSubmission']);
        Route::get('/challenges/{challenge}/submissions/mine', [ChallengeController::class, 'mySubmissionsForChallenge']);
        Route::post('/challenge-submissions/{submission}/withdraw', [ChallengeController::class, 'withdrawSubmission']);
        Route::prefix('ai')->group(function (): void {
            Route::post('/captions', [AiAssistantController::class, 'captions']);
            Route::post('/ideas', [AiAssistantController::class, 'ideas']);
        });

        Route::middleware('admin')->prefix('admin')->group(function (): void {
            Route::get('/dashboard', [AdminDashboardController::class, 'dashboard']);
            Route::get('/reports/videos', [AdminDashboardController::class, 'videoReports']);
            Route::patch('/reports/videos/{videoReport}', [AdminDashboardController::class, 'updateVideoReport']);
            Route::get('/payout-requests', [AdminPayoutController::class, 'index']);
            Route::patch('/payout-requests/{payoutRequest}', [AdminPayoutController::class, 'update']);
            Route::get('/moderation/cases', [ContentModerationController::class, 'index']);
            Route::get('/moderation/cases/{contentModerationCase}', [ContentModerationController::class, 'show']);
            Route::patch('/moderation/cases/{contentModerationCase}', [ContentModerationController::class, 'update']);
            Route::post('/moderation/videos/{video}/rescan', [ContentModerationController::class, 'rescanVideo']);
            Route::post('/moderation/comments/{comment}/rescan', [ContentModerationController::class, 'rescanComment']);
        });

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/suggested', [ConversationController::class, 'suggested']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
    });
});
