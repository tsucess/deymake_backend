<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorVerificationRequestResource;
use App\Models\CreatorVerificationRequest;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $requests = PaginatedJson::paginate(
            CreatorVerificationRequest::query()
                ->with([
                    'user' => fn ($query) => $query->withProfileAggregates($request->user()),
                    'reviewer' => fn ($query) => $query->withProfileAggregates($request->user()),
                ])
                ->when($status !== '', fn ($query) => $query->where('status', $status))
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
}