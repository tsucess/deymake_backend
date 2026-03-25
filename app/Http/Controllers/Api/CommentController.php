<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\UserNotification;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    public function index(Request $request, Video $video): JsonResponse
    {
        $this->ensureVideoVisible($request, $video);

        $comments = Comment::query()
            ->with('user')
            ->where('video_id', $video->id)
            ->whereNull('parent_id')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Comments retrieved successfully.',
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

        $this->notify(
            $video->user_id,
            $request->user()->id,
            'comment',
            'New comment on your video',
            $request->user()->name.' commented on your video.',
            ['videoId' => $video->id, 'commentId' => $comment->id]
        );

        $comment->load('user');

        return response()->json([
            'message' => 'Comment created successfully.',
            'data' => [
                'comment' => new CommentResource($comment),
            ],
        ], 201);
    }

    public function replies(Request $request, Comment $comment): JsonResponse
    {
        $comment->loadMissing('video');
        $this->ensureVideoVisible($request, $comment->video);

        $replies = $comment->replies()->with('user')->latest()->get();

        return response()->json([
            'message' => 'Replies retrieved successfully.',
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

        $this->notify(
            $comment->user_id,
            $request->user()->id,
            'reply',
            'New reply to your comment',
            $request->user()->name.' replied to your comment.',
            ['videoId' => $comment->video_id, 'commentId' => $comment->id, 'replyId' => $reply->id]
        );

        $reply->load('user');

        return response()->json([
            'message' => 'Reply created successfully.',
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
        $comment->load('user');

        return response()->json([
            'message' => 'Comment updated successfully.',
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
            'message' => 'Comment deleted successfully.',
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

            $this->notify(
                $comment->user_id,
                $request->user()->id,
                'comment_'.$type,
                ucfirst($type).' on your comment',
                $request->user()->name.' '.$type.'d your comment.',
                ['commentId' => $comment->id, 'videoId' => $comment->video_id]
            );
        } else {
            $query->delete();
        }

        $comment->load('user');

        return response()->json([
            'message' => 'Comment '.($active ? $type.'d' : $type.' removed').' successfully.',
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

    private function notify(int $recipientId, int $actorId, string $type, string $title, string $body, array $data = []): void
    {
        if ($recipientId === $actorId) {
            return;
        }

        UserNotification::create([
            'user_id' => $recipientId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }
}