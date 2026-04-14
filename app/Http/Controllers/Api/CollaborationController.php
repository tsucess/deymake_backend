<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collaboration\StoreCollaborationInviteRequest;
use App\Http\Requests\Collaboration\UpdateCollaborationInviteRequest;
use App\Http\Resources\CollaborationInviteResource;
use App\Models\CollaborationInvite;
use App\Models\Video;
use App\Services\ConversationLinkService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollaborationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $scope = trim($request->string('scope')->toString()) ?: 'inbox';
        $status = trim($request->string('status')->toString());

        $invites = CollaborationInvite::query()
            ->withCount('deliverables')
            ->with([
                'inviter' => fn ($query) => $query->withProfileAggregates($request->user()),
                'invitee' => fn ($query) => $query->withProfileAggregates($request->user()),
                'sourceVideo.user' => fn ($query) => $query->withProfileAggregates($request->user()),
            ])
            ->when($scope === 'sent', fn ($query) => $query->where('inviter_id', $request->user()->id), fn ($query) => $query->where('invitee_id', $request->user()->id))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.collaborations.invites_retrieved'),
            'data' => [
                'invites' => CollaborationInviteResource::collection($invites),
            ],
        ]);
    }

    public function store(StoreCollaborationInviteRequest $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        abort_if((int) $validated['inviteeId'] === (int) $request->user()->id, 422, __('messages.collaborations.self_not_allowed'));

        $video = Video::query()->findOrFail($validated['videoId']);
        abort_if($video->user_id !== $request->user()->id, 403);

        $existingPending = CollaborationInvite::query()
            ->where('inviter_id', $request->user()->id)
            ->where('invitee_id', $validated['inviteeId'])
            ->where('source_video_id', $video->id)
            ->where('type', $validated['type'] ?? 'duet')
            ->where('status', 'pending')
            ->first();

        abort_if($existingPending, 422, __('messages.collaborations.pending_exists'));

        $invite = CollaborationInvite::query()->create([
            'inviter_id' => $request->user()->id,
            'invitee_id' => (int) $validated['inviteeId'],
            'source_video_id' => $video->id,
            'type' => $validated['type'] ?? 'duet',
            'status' => 'pending',
            'message' => $validated['message'] ?? null,
            'expires_at' => now()->addDays((int) ($validated['expiresInDays'] ?? 7)),
        ]);

        $invite->loadCount('deliverables')->load([
            'inviter' => fn ($query) => $query->withProfileAggregates($request->user()),
            'invitee' => fn ($query) => $query->withProfileAggregates($request->user()),
            'sourceVideo.user' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        UserNotifier::sendTranslated(
            $invite->invitee_id,
            $request->user()->id,
            'collaboration_invite',
            'messages.notifications.collaboration_invite_title',
            'messages.notifications.collaboration_invite_body',
            ['name' => $request->user()->name, 'type' => $invite->type, 'title' => $video->title ?: __('messages.videos.retrieved')],
            ['inviteId' => $invite->id, 'videoId' => $video->id]
        );

        DeveloperWebhookDispatcher::dispatch($request->user(), 'collaboration.invite.created', [
            'type' => 'collaboration.invite.created',
            'inviteId' => $invite->id,
            'inviteeId' => $invite->invitee_id,
            'videoId' => $invite->source_video_id,
            'status' => $invite->status,
        ]);

        return response()->json([
            'message' => __('messages.collaborations.invite_created'),
            'data' => [
                'invite' => new CollaborationInviteResource($invite),
            ],
        ], 201);
    }

    public function update(
        UpdateCollaborationInviteRequest $request,
        CollaborationInvite $collaborationInvite,
        ConversationLinkService $conversationLinkService,
    ): JsonResponse {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $action = $validated['action'];

        $this->expireIfNeeded($collaborationInvite);
        abort_if($collaborationInvite->status !== 'pending', 422, __('messages.collaborations.not_pending'));

        if ($action === 'cancel') {
            abort_if($collaborationInvite->inviter_id !== $request->user()->id, 403);
            $collaborationInvite->forceFill([
                'status' => 'cancelled',
                'responded_at' => now(),
            ])->save();

            UserNotifier::sendTranslated(
                $collaborationInvite->invitee_id,
                $request->user()->id,
                'collaboration_invite_cancelled',
                'messages.notifications.collaboration_cancelled_title',
                'messages.notifications.collaboration_cancelled_body',
                ['name' => $request->user()->name],
                ['inviteId' => $collaborationInvite->id, 'videoId' => $collaborationInvite->source_video_id]
            );
        } else {
            abort_if($collaborationInvite->invitee_id !== $request->user()->id, 403);

            $updates = [
                'status' => $action === 'accept' ? 'accepted' : 'rejected',
                'responded_at' => now(),
            ];

            if ($action === 'accept') {
                $conversation = $conversationLinkService->findOrCreateDirectConversation($collaborationInvite->inviter, $collaborationInvite->invitee);
                $updates['conversation_id'] = $conversation->id;
            }

            $collaborationInvite->forceFill($updates)->save();

            UserNotifier::sendTranslated(
                $collaborationInvite->inviter_id,
                $request->user()->id,
                $action === 'accept' ? 'collaboration_invite_accepted' : 'collaboration_invite_rejected',
                $action === 'accept' ? 'messages.notifications.collaboration_accepted_title' : 'messages.notifications.collaboration_rejected_title',
                $action === 'accept' ? 'messages.notifications.collaboration_accepted_body' : 'messages.notifications.collaboration_rejected_body',
                ['name' => $request->user()->name],
                [
                    'inviteId' => $collaborationInvite->id,
                    'videoId' => $collaborationInvite->source_video_id,
                    'conversationId' => $collaborationInvite->conversation_id,
                ]
            );
        }

        $collaborationInvite->loadCount('deliverables')->load([
            'inviter' => fn ($query) => $query->withProfileAggregates($request->user()),
            'invitee' => fn ($query) => $query->withProfileAggregates($request->user()),
            'sourceVideo.user' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        DeveloperWebhookDispatcher::dispatch($collaborationInvite->inviter, 'collaboration.invite.updated', [
            'type' => 'collaboration.invite.updated',
            'inviteId' => $collaborationInvite->id,
            'status' => $collaborationInvite->status,
            'conversationId' => $collaborationInvite->conversation_id,
        ]);

        return response()->json([
            'message' => __('messages.collaborations.invite_updated'),
            'data' => [
                'invite' => new CollaborationInviteResource($collaborationInvite),
            ],
        ]);
    }

    private function expireIfNeeded(CollaborationInvite $collaborationInvite): void
    {
        if ($collaborationInvite->status === 'pending' && $collaborationInvite->expires_at?->isPast()) {
            $collaborationInvite->forceFill([
                'status' => 'expired',
                'responded_at' => now(),
            ])->save();
        }
    }
}