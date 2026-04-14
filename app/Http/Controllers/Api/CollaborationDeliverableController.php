<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collaboration\StoreCollaborationDeliverableRequest;
use App\Http\Requests\Collaboration\UpdateCollaborationDeliverableRequest;
use App\Http\Resources\CollaborationDeliverableResource;
use App\Models\CollaborationDeliverable;
use App\Models\CollaborationInvite;
use App\Models\Video;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollaborationDeliverableController extends Controller
{
    public function index(Request $request, CollaborationInvite $collaborationInvite): JsonResponse
    {
        SupportedLocales::apply($request);
        $this->ensureParticipant($collaborationInvite, $request->user()->id);

        $deliverables = $collaborationInvite->deliverables()
            ->with($this->resourceRelations($request))
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.collaborations.deliverables_retrieved'),
            'data' => [
                'deliverables' => CollaborationDeliverableResource::collection($deliverables),
            ],
        ]);
    }

    public function store(
        StoreCollaborationDeliverableRequest $request,
        CollaborationInvite $collaborationInvite,
    ): JsonResponse {
        SupportedLocales::apply($request);
        $this->ensureActiveInvite($collaborationInvite, $request->user()->id);

        $validated = $request->validated();
        $draftVideo = $this->resolveDraftVideo($validated['draftVideoId'] ?? null, $request->user()->id);

        $deliverable = CollaborationDeliverable::query()->create([
            'collaboration_invite_id' => $collaborationInvite->id,
            'created_by' => $request->user()->id,
            'draft_video_id' => $draftVideo?->id,
            'title' => $validated['title'] ?? null,
            'brief' => $validated['brief'] ?? null,
            'status' => 'drafting',
        ]);

        $deliverable->load($this->resourceRelations($request));

        DeveloperWebhookDispatcher::dispatch($collaborationInvite->inviter, 'collaboration.deliverable.created', [
            'type' => 'collaboration.deliverable.created',
            'deliverableId' => $deliverable->id,
            'inviteId' => $collaborationInvite->id,
            'status' => $deliverable->status,
        ]);

        if ($collaborationInvite->invitee_id !== $collaborationInvite->inviter_id) {
            DeveloperWebhookDispatcher::dispatch($collaborationInvite->invitee, 'collaboration.deliverable.created', [
                'type' => 'collaboration.deliverable.created',
                'deliverableId' => $deliverable->id,
                'inviteId' => $collaborationInvite->id,
                'status' => $deliverable->status,
            ]);
        }

        return response()->json([
            'message' => __('messages.collaborations.deliverable_created'),
            'data' => [
                'deliverable' => new CollaborationDeliverableResource($deliverable),
            ],
        ], 201);
    }

    public function update(
        UpdateCollaborationDeliverableRequest $request,
        CollaborationDeliverable $collaborationDeliverable,
    ): JsonResponse {
        SupportedLocales::apply($request);

        $collaborationDeliverable->loadMissing('collaborationInvite');
        $invite = $collaborationDeliverable->collaborationInvite;
        $this->ensureParticipant($invite, $request->user()->id);

        $validated = $request->validated();
        $action = $validated['action'];

        match ($action) {
            'save' => $this->saveDraft($collaborationDeliverable, $validated, $request->user()->id),
            'submit' => $this->submitDeliverable($collaborationDeliverable, $validated, $request->user()->id),
            'request_changes' => $this->requestChanges($collaborationDeliverable, $validated, $request->user()->id),
            'approve' => $this->approve($collaborationDeliverable, $request->user()->id),
            'cancel' => $this->cancel($collaborationDeliverable, $request->user()->id),
        };

        $collaborationDeliverable->refresh()->load($this->resourceRelations($request));
        $this->dispatchWebhook($invite, $collaborationDeliverable);

        return response()->json([
            'message' => __('messages.collaborations.deliverable_updated'),
            'data' => [
                'deliverable' => new CollaborationDeliverableResource($collaborationDeliverable),
            ],
        ]);
    }

    private function saveDraft(CollaborationDeliverable $deliverable, array $validated, int $userId): void
    {
        abort_if($deliverable->created_by !== $userId, 403);
        abort_if(! in_array($deliverable->status, ['drafting', 'changes_requested'], true), 422, __('messages.collaborations.deliverable_not_editable'));

        $draftVideo = $this->resolveDraftVideo($validated['draftVideoId'] ?? $deliverable->draft_video_id, $userId);
        $deliverable->forceFill([
            'title' => $validated['title'] ?? $deliverable->title,
            'brief' => $validated['brief'] ?? $deliverable->brief,
            'draft_video_id' => $draftVideo?->id,
        ])->save();
    }

    private function submitDeliverable(CollaborationDeliverable $deliverable, array $validated, int $userId): void
    {
        abort_if($deliverable->created_by !== $userId, 403);

        $draftVideo = $this->resolveDraftVideo($validated['draftVideoId'] ?? $deliverable->draft_video_id, $userId);
        abort_if(! $draftVideo, 422, __('messages.collaborations.draft_video_required'));

        $deliverable->forceFill([
            'title' => $validated['title'] ?? $deliverable->title,
            'brief' => $validated['brief'] ?? $deliverable->brief,
            'draft_video_id' => $draftVideo->id,
            'status' => 'submitted',
            'feedback' => null,
            'submitted_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        $invite = $deliverable->collaborationInvite;
        $recipientId = $invite->inviter_id === $userId ? $invite->invitee_id : $invite->inviter_id;
        UserNotifier::sendTranslated(
            $recipientId,
            $userId,
            'collaboration_deliverable_submitted',
            'messages.notifications.collaboration_deliverable_submitted_title',
            'messages.notifications.collaboration_deliverable_submitted_body',
            ['title' => $deliverable->title ?: __('messages.collaborations.deliverable')],
            ['deliverableId' => $deliverable->id, 'inviteId' => $invite->id]
        );
    }

    private function requestChanges(CollaborationDeliverable $deliverable, array $validated, int $userId): void
    {
        abort_if($deliverable->created_by === $userId, 403);
        abort_if($deliverable->status !== 'submitted', 422, __('messages.collaborations.deliverable_not_reviewable'));

        $deliverable->forceFill([
            'status' => 'changes_requested',
            'feedback' => $validated['feedback'] ?? $deliverable->feedback,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ])->save();

        UserNotifier::sendTranslated(
            $deliverable->created_by,
            $userId,
            'collaboration_deliverable_changes_requested',
            'messages.notifications.collaboration_changes_requested_title',
            'messages.notifications.collaboration_changes_requested_body',
            ['title' => $deliverable->title ?: __('messages.collaborations.deliverable')],
            ['deliverableId' => $deliverable->id, 'inviteId' => $deliverable->collaboration_invite_id]
        );
    }

    private function approve(CollaborationDeliverable $deliverable, int $userId): void
    {
        abort_if($deliverable->created_by === $userId, 403);
        abort_if($deliverable->status !== 'submitted', 422, __('messages.collaborations.deliverable_not_reviewable'));

        $deliverable->forceFill([
            'status' => 'approved',
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ])->save();

        UserNotifier::sendTranslated(
            $deliverable->created_by,
            $userId,
            'collaboration_deliverable_approved',
            'messages.notifications.collaboration_approved_title',
            'messages.notifications.collaboration_approved_body',
            ['title' => $deliverable->title ?: __('messages.collaborations.deliverable')],
            ['deliverableId' => $deliverable->id, 'inviteId' => $deliverable->collaboration_invite_id]
        );
    }

    private function cancel(CollaborationDeliverable $deliverable, int $userId): void
    {
        abort_if($deliverable->created_by !== $userId && $deliverable->collaborationInvite->inviter_id !== $userId && $deliverable->collaborationInvite->invitee_id !== $userId, 403);
        abort_if($deliverable->status === 'approved', 422, __('messages.collaborations.deliverable_not_editable'));

        $deliverable->forceFill([
            'status' => 'cancelled',
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ])->save();
    }

    private function resolveDraftVideo(mixed $draftVideoId, int $userId): ?Video
    {
        if (! $draftVideoId) {
            return null;
        }

        return Video::query()
            ->where('user_id', $userId)
            ->where('is_draft', true)
            ->findOrFail($draftVideoId);
    }

    private function ensureParticipant(CollaborationInvite $invite, int $userId): void
    {
        abort_unless(in_array($userId, [$invite->inviter_id, $invite->invitee_id], true), 403);
    }

    private function ensureActiveInvite(CollaborationInvite $invite, int $userId): void
    {
        $this->ensureParticipant($invite, $userId);
        abort_if($invite->status !== 'accepted', 422, __('messages.collaborations.invite_must_be_accepted'));
    }

    /**
     * @return array<int, mixed>
     */
    private function resourceRelations(Request $request): array
    {
        return [
            'collaborationInvite',
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'reviewer' => fn ($query) => $query->withProfileAggregates($request->user()),
            'draftVideo',
        ];
    }

    private function dispatchWebhook(CollaborationInvite $invite, CollaborationDeliverable $deliverable): void
    {
        $payload = [
            'type' => 'collaboration.deliverable.updated',
            'deliverableId' => $deliverable->id,
            'inviteId' => $invite->id,
            'status' => $deliverable->status,
            'draftVideoId' => $deliverable->draft_video_id,
        ];

        DeveloperWebhookDispatcher::dispatch($invite->inviter, 'collaboration.deliverable.updated', $payload);

        if ($invite->invitee_id !== $invite->inviter_id) {
            DeveloperWebhookDispatcher::dispatch($invite->invitee, 'collaboration.deliverable.updated', $payload);
        }
    }
}