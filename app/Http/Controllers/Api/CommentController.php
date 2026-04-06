<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    public function index(Request $request, Video $video): JsonResponse
    {
        $this->ensureVideoVisible($request, $video);
        $viewer = auth('sanctum')->user() ?? $request->user();

        $comments = Comment::query()
            ->withApiResourceData($viewer)
            ->where('video_id', $video->id)
            ->whereNull('parent_id')
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.comments.retrieved'),
            'data' => [
                'comments' => CommentResource::collection($comments),
            ],
        ]);
    }

    public function store(Request $request, Video $video): JsonResponse
    {
        $this->ensureVideoVisible($request, $video);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $comment = Comment::create([
            'video_id' => $video->id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        if ($video->is_live) {
            $video->increment('live_comments_count');
        }

        UserNotifier::sendTranslated(
            $video->user_id,
            $request->user()->id,
            'comment',
            'messages.notifications.comment_title',
            'messages.notifications.comment_body',
            ['name' => $request->user()->name],
            ['videoId' => $video->id, 'commentId' => $comment->id]
        );

        $comment = $this->loadCommentForResource($comment->id, $request->user());

        return response()->json([
            'message' => __('messages.comments.created'),
            'data' => [
                'comment' => new CommentResource($comment),
            ],
        ], 201);
    }

    public function replies(Request $request, Comment $comment): JsonResponse
    {
        $comment->loadMissing('video');
        $this->ensureVideoVisible($request, $comment->video);
        $viewer = auth('sanctum')->user() ?? $request->user();

        $replies = $comment->replies()->withApiResourceData($viewer)->latest()->get();

        return response()->json([
            'message' => __('messages.comments.replies_retrieved'),
            'data' => [
                'replies' => CommentResource::collection($replies),
            ],
        ]);
    }

    public function storeReply(Request $request, Comment $comment): JsonResponse
    {
        $comment->loadMissing('video');
        $this->ensureVideoVisible($request, $comment->video);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $reply = Comment::create([
            'video_id' => $comment->video_id,
            'user_id' => $request->user()->id,
            'parent_id' => $comment->id,
            'body' => $validated['body'],
        ]);

        if ($comment->video?->is_live) {
            $comment->video->increment('live_comments_count');
        }

        $this->sendReplyNotifications($comment, $request->user(), $reply);

        $reply = $this->loadCommentForResource($reply->id, $request->user());

        return response()->json([
            'message' => __('messages.comments.reply_created'),
            'data' => [
                'reply' => new CommentResource($reply),
            ],
        ], 201);
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        abort_if($comment->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $comment->forceFill(['body' => $validated['body']])->save();
        $comment = $this->loadCommentForResource($comment->id, $request->user());

        return response()->json([
            'message' => __('messages.comments.updated'),
            'data' => [
                'comment' => new CommentResource($comment),
            ],
        ]);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        abort_if($comment->user_id !== $request->user()->id, 403);

        $comment->delete();

        return response()->json([
            'message' => __('messages.comments.deleted'),
        ]);
    }

    public function like(Request $request, Comment $comment): JsonResponse
    {
        return $this->toggleInteraction($request, $comment, 'like', true);
    }

    public function unlike(Request $request, Comment $comment): JsonResponse
    {
        return $this->toggleInteraction($request, $comment, 'like', false);
    }

    public function dislike(Request $request, Comment $comment): JsonResponse
    {
        return $this->toggleInteraction($request, $comment, 'dislike', true);
    }

    public function undislike(Request $request, Comment $comment): JsonResponse
    {
        return $this->toggleInteraction($request, $comment, 'dislike', false);
    }

    private function toggleInteraction(Request $request, Comment $comment, string $type, bool $active): JsonResponse
    {
        $query = DB::table('comment_interactions')
            ->where('comment_id', $comment->id)
            ->where('user_id', $request->user()->id)
            ->where('type', $type);

        if ($active) {
            DB::table('comment_interactions')->updateOrInsert(
                [
                    'comment_id' => $comment->id,
                    'user_id' => $request->user()->id,
                    'type' => $type,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if ($type === 'like') {
                DB::table('comment_interactions')
                    ->where('comment_id', $comment->id)
                    ->where('user_id', $request->user()->id)
                    ->where('type', 'dislike')
                    ->delete();
            }

            if ($type === 'dislike') {
                DB::table('comment_interactions')
                    ->where('comment_id', $comment->id)
                    ->where('user_id', $request->user()->id)
                    ->where('type', 'like')
                    ->delete();
            }

            UserNotifier::sendTranslated(
                $comment->user_id,
                $request->user()->id,
                'comment_'.$type,
                ...$this->interactionNotification($request->user()->name, $type, $comment)
            );
        } else {
            $query->delete();
        }

        $comment = $this->loadCommentForResource($comment->id, $request->user());

        return response()->json([
            'message' => __($this->interactionMessageKey($type, $active)),
            'data' => [
                'comment' => new CommentResource($comment),
            ],
        ]);
    }

    private function ensureVideoVisible(Request $request, Video $video): void
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        if ($video->is_draft && (! $viewer || $viewer->id !== $video->user_id)) {
            abort(404);
        }
    }

    private function loadCommentForResource(int $commentId, ?User $viewer): Comment
    {
        return Comment::query()
            ->withApiResourceData($viewer)
            ->findOrFail($commentId);
    }

    private function sendReplyNotifications(Comment $comment, User $actor, Comment $reply): void
    {
        foreach ($this->replyNotificationRecipients($comment) as $recipientId) {
            UserNotifier::sendTranslated(
                $recipientId,
                $actor->id,
                'reply',
                'messages.notifications.reply_title',
                'messages.notifications.reply_body',
                ['name' => $actor->name],
                ['videoId' => $comment->video_id, 'commentId' => $comment->id, 'replyId' => $reply->id]
            );
        }
    }

    private function replyNotificationRecipients(Comment $comment): array
    {
        $recipientIds = [];
        $current = $comment;

        while ($current) {
            $recipientIds[] = (int) $current->user_id;
            $current->loadMissing('parent');
            $current = $current->parent;
        }

        return array_values(array_unique(array_filter($recipientIds)));
    }

    private function interactionMessageKey(string $type, bool $active): string
    {
        return match ([$type, $active]) {
            ['like', true] => 'messages.comments.liked',
            ['like', false] => 'messages.comments.like_removed',
            ['dislike', true] => 'messages.comments.disliked',
            ['dislike', false] => 'messages.comments.dislike_removed',
        };
    }

    private function interactionNotification(string $actorName, string $type, Comment $comment): array
    {
        [$titleKey, $bodyKey] = match ($type) {
            'like' => ['comment_like_title', 'comment_like_body'],
            'dislike' => ['comment_dislike_title', 'comment_dislike_body'],
        };

        return [
            'messages.notifications.'.$titleKey,
            'messages.notifications.'.$bodyKey,
            ['name' => $actorName],
            ['commentId' => $comment->id, 'videoId' => $comment->video_id],
        ];
    }
}