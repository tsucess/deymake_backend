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

        UserNotifier::send(
            $video->user_id,
            $request->user()->id,
            'comment',
            'New comment on your video',
            $request->user()->name.' commented on your video.',
            ['videoId' => $video->id, 'commentId' => $comment->id]
        );

        $comment = $this->loadCommentForResource($comment->id, $request->user());

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
        $viewer = auth('sanctum')->user() ?? $request->user();

        $replies = $comment->replies()->withApiResourceData($viewer)->latest()->get();

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

        UserNotifier::send(
            $comment->user_id,
            $request->user()->id,
            'reply',
            'New reply to your comment',
            $request->user()->name.' replied to your comment.',
            ['videoId' => $comment->video_id, 'commentId' => $comment->id, 'replyId' => $reply->id]
        );

        $reply = $this->loadCommentForResource($reply->id, $request->user());

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
        $comment = $this->loadCommentForResource($comment->id, $request->user());

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

            UserNotifier::send(
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

        $comment = $this->loadCommentForResource($comment->id, $request->user());

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

    private function loadCommentForResource(int $commentId, ?User $viewer): Comment
    {
        return Comment::query()
            ->withApiResourceData($viewer)
            ->findOrFail($commentId);
    }
}