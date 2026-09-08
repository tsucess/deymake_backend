<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorVerificationRequestResource;
use App\Models\CreatorVerificationRequest;
use App\Support\AuditLogger;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Creator verification controller.
 *
 * User-facing side of the "verified creator" application flow;
 * admin review happens in the admin module.
 *
 * Routes: GET /creator-verification, POST /creator-verification.
 * Frontend consumers: pages/Settings.jsx (verification section).
 * Related: CreatorVerificationRequest model.
 * See PROJECT_OVERVIEW.md §3.24 for the full data-flow map.
 */
class CreatorVerificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $verificationRequest = CreatorVerificationRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['user' => fn ($query) => $query->withProfileAggregates($request->user()), 'reviewer' => fn ($query) => $query->withProfileAggregates($request->user())])
            ->latest('id')
            ->first();

        return response()->json([
            'message' => __('messages.creator_verification.status_retrieved'),
            'data' => [
                'status' => $request->user()->creator_verification_status ?: 'unsubmitted',
                'verifiedAt' => $request->user()->creator_verified_at?->toISOString(),
                'request' => $verificationRequest ? new CreatorVerificationRequestResource($verificationRequest) : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'legalName' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:120'],
            'documentType' => ['required', Rule::in(['id_card', 'passport', 'drivers_license', 'business_document'])],
            'documentUrl' => ['required', 'url', 'max:2048'],
            'about' => ['nullable', 'string'],
            'socialLinks' => ['nullable', 'array'],
            'socialLinks.*' => ['string', 'max:2048'],
        ]);

        $verificationRequest = CreatorVerificationRequest::query()
            ->firstOrNew([
                'user_id' => $request->user()->id,
                'status' => 'pending',
            ]);

        $verificationRequest->fill([
            'legal_name' => $validated['legalName'],
            'country' => $validated['country'],
            'document_type' => $validated['documentType'],
            'document_url' => $validated['documentUrl'],
            'about' => $validated['about'] ?? null,
            'social_links' => $validated['socialLinks'] ?? [],
            'review_notes' => null,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
        ])->save();

        $request->user()->forceFill([
            'creator_verification_status' => 'pending',
            'creator_verified_at' => null,
            'creator_verification_notes' => null,
        ])->save();

        $verificationRequest->load([
            'user' => fn ($query) => $query->withProfileAggregates($request->user()),
            'reviewer' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        DeveloperWebhookDispatcher::dispatch($request->user(), 'creator.verification.requested', [
            'type' => 'creator.verification.requested',
            'requestId' => $verificationRequest->id,
            'status' => $verificationRequest->status,
        ]);

        return response()->json([
            'message' => __('messages.creator_verification.submitted'),
            'data' => [
                'request' => new CreatorVerificationRequestResource($verificationRequest),
            ],
        ], $verificationRequest->wasRecentlyCreated ? 201 : 200);
    }

    public function indexAdmin(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $status = trim($request->string('status')->toString());
        $search = trim($request->string('q')->toString());
        $from = trim($request->string('from')->toString());
        $to = trim($request->string('to')->toString());

        $requests = PaginatedJson::paginate(
            CreatorVerificationRequest::query()
                ->with([
                    'user' => fn ($query) => $query->withProfileAggregates($request->user()),
                    'reviewer' => fn ($query) => $query->withProfileAggregates($request->user()),
                ])
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($searchQuery) use ($search): void {
                        $searchQuery
                            ->where('legal_name', 'like', '%'.$search.'%')
                            ->orWhere('country', 'like', '%'.$search.'%')
                            ->when(ctype_digit($search), fn ($idQuery) => $idQuery->orWhere('id', (int) $search))
                            ->orWhereHas('user', function ($userQuery) use ($search): void {
                                $userQuery
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('username', 'like', '%'.$search.'%')
                                    ->orWhere('email', 'like', '%'.$search.'%');
                            });
                    });
                })
                ->when($from !== '', fn ($query) => $query->whereDate('submitted_at', '>=', $from))
                ->when($to !== '', fn ($query) => $query->whereDate('submitted_at', '<=', $to))
                ->latest('submitted_at'),
            $request
        );

        return response()->json([
            'message' => __('messages.creator_verification.admin_requests_retrieved'),
            'data' => [
                'requests' => PaginatedJson::items($request, $requests, CreatorVerificationRequestResource::class),
            ],
            'meta' => [
                'requests' => PaginatedJson::meta($requests),
                'summary' => [
                    'total' => CreatorVerificationRequest::query()->count(),
                    'pending' => CreatorVerificationRequest::query()->where('status', 'pending')->count(),
                    'approved' => CreatorVerificationRequest::query()->where('status', 'approved')->count(),
                    'rejected' => CreatorVerificationRequest::query()->where('status', 'rejected')->count(),
                    'needsMoreInfo' => CreatorVerificationRequest::query()->where('status', 'needs_more_info')->count(),
                ],
            ],
        ]);
    }

    public function showAdmin(Request $request, CreatorVerificationRequest $creatorVerificationRequest): JsonResponse
    {
        SupportedLocales::apply($request);

        $creatorVerificationRequest->load([
            'user' => fn ($query) => $query->withProfileAggregates($request->user()),
            'reviewer' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        // Reading an applicant's submitted identity documents is a sensitive
        // action; record who accessed which request for the audit trail.
        AuditLogger::record('admin.creator_verification_viewed', $creatorVerificationRequest, (int) $request->user()->id, [
            'userId' => $creatorVerificationRequest->user_id,
            'status' => $creatorVerificationRequest->status,
        ], $request->ip());

        return response()->json([
            'message' => __('messages.creator_verification.admin_request_retrieved'),
            'data' => [
                'request' => new CreatorVerificationRequestResource($creatorVerificationRequest),
            ],
        ]);
    }

    public function updateAdmin(Request $request, CreatorVerificationRequest $creatorVerificationRequest): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'needs_more_info'])],
            'reviewNotes' => ['nullable', 'string'],
        ]);

        $status = $validated['status'];
        $previousStatus = $creatorVerificationRequest->status;
        $creatorVerificationRequest->forceFill([
            'status' => $status,
            'review_notes' => $validated['reviewNotes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $creatorVerificationRequest->user->forceFill([
            'creator_verification_status' => $status,
            'creator_verified_at' => $status === 'approved' ? now() : null,
            'creator_verification_notes' => $validated['reviewNotes'] ?? null,
        ])->save();

        UserNotifier::sendTranslated(
            $creatorVerificationRequest->user_id,
            $request->user()->id,
            'creator_verification_reviewed',
            'messages.notifications.creator_verification_title',
            'messages.notifications.creator_verification_body',
            ['status' => str_replace('_', ' ', $status)],
            ['creatorVerificationRequestId' => $creatorVerificationRequest->id, 'status' => $status],
        );

        DeveloperWebhookDispatcher::dispatch($creatorVerificationRequest->user, 'creator.verification.updated', [
            'type' => 'creator.verification.updated',
            'requestId' => $creatorVerificationRequest->id,
            'status' => $status,
            'reviewedBy' => $request->user()->id,
        ]);

        AuditLogger::record($this->reviewAction($status), $creatorVerificationRequest, (int) $request->user()->id, [
            'from' => $previousStatus,
            'to' => $status,
            'userId' => $creatorVerificationRequest->user_id,
            'notes' => $validated['reviewNotes'] ?? null,
        ], $request->ip());

        $creatorVerificationRequest->load([
            'user' => fn ($query) => $query->withProfileAggregates($request->user()),
            'reviewer' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        return response()->json([
            'message' => __('messages.creator_verification.reviewed'),
            'data' => [
                'request' => new CreatorVerificationRequestResource($creatorVerificationRequest),
            ],
        ]);
    }

    private function reviewAction(string $status): string
    {
        return match ($status) {
            'approved' => 'admin.creator_verification_approved',
            'rejected' => 'admin.creator_verification_rejected',
            default => 'admin.creator_verification_more_info_requested',
        };
    }
}
