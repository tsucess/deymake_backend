<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VideoInteractionController extends Controller
{
    public function like(Request $request, Video $video): JsonResponse
    {
        return $this->toggleVideoInteraction($request, $video, 'like', true);
    }

    public function unlike(Request $request, Video $video): JsonResponse
    {
        return $this->toggleVideoInteraction($request, $video, 'like', false);
    }

    public function dislike(Request $request, Video $video): JsonResponse
    {
        return $this->toggleVideoInteraction($request, $video, 'dislike', true);
    }

    public function undislike(Request $request, Video $video): JsonResponse
    {
        return $this->toggleVideoInteraction($request, $video, 'dislike', false);
    }

    public function save(Request $request, Video $video): JsonResponse
    {
        return $this->toggleVideoInteraction($request, $video, 'save', true);
    }

    public function unsave(Request $request, Video $video): JsonResponse
    {
        return $this->toggleVideoInteraction($request, $video, 'save', false);
    }

    public function subscribe(Request $request, User $creator): JsonResponse
    {
        abort_if($creator->is($request->user()), 422, __('messages.subscriptions.self_not_allowed'));

        DB::table('subscriptions')->updateOrInsert(
            [
                'user_id' => $request->user()->id,
                'creator_id' => $creator->id,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        UserNotifier::sendTranslated(
            $creator->id,
            $request->user()->id,
            'subscription',
            'messages.notifications.subscription_title',
            'messages.notifications.subscription_body',
            ['name' => $request->user()->name],
            ['creatorId' => $creator->id],
        );

        $creator->loadCount('subscribers');

        return response()->json([
            'message' => __('messages.subscriptions.created'),
            'data' => [
                'creator' => [
                    'id' => $creator->id,
                    'subscriberCount' => (int) $creator->subscribers_count,
                    'subscribed' => true,
                ],
            ],
        ]);
    }

    public function unsubscribe(Request $request, User $creator): JsonResponse
    {
        DB::table('subscriptions')
            ->where('user_id', $request->user()->id)
            ->where('creator_id', $creator->id)
            ->delete();

        $creator->loadCount('subscribers');

        return response()->json([
            'message' => __('messages.subscriptions.removed'),
            'data' => [
                'creator' => [
                    'id' => $creator->id,
                    'subscriberCount' => (int) $creator->subscribers_count,
                    'subscribed' => false,
                ],
            ],
        ]);
    }

    private function toggleVideoInteraction(Request $request, Video $video, string $type, bool $active): JsonResponse
    {
        $query = DB::table('video_interactions')
            ->where('video_id', $video->id)
            ->where('user_id', $request->user()->id)
            ->where('type', $type);

        if ($active) {
            DB::table('video_interactions')->updateOrInsert(
                [
                    'video_id' => $video->id,
                    'user_id' => $request->user()->id,
                    'type' => $type,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if ($type === 'like') {
                DB::table('video_interactions')
                    ->where('video_id', $video->id)
                    ->where('user_id', $request->user()->id)
                    ->where('type', 'dislike')
                    ->delete();
            }

            if ($type === 'dislike') {
                DB::table('video_interactions')
                    ->where('video_id', $video->id)
                    ->where('user_id', $request->user()->id)
                    ->where('type', 'like')
                    ->delete();
            }

            if (in_array($type, ['like', 'dislike'], true)) {
                UserNotifier::sendTranslated(
                    $video->user_id,
                    $request->user()->id,
                    'video_'.$type,
                    ...$this->interactionNotification($request->user()->name, $type, $video)
                );
            }
        } else {
            $query->delete();
        }

        $video = Video::query()
            ->withApiResourceData($request->user())
            ->findOrFail($video->id);

        return response()->json([
            'message' => __($this->interactionMessageKey($type, $active)),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    private function interactionMessageKey(string $type, bool $active): string
    {
        return match ([$type, $active]) {
            ['like', true] => 'messages.videos.liked',
            ['like', false] => 'messages.videos.like_removed',
            ['dislike', true] => 'messages.videos.disliked',
            ['dislike', false] => 'messages.videos.dislike_removed',
            ['save', true] => 'messages.videos.saved',
            ['save', false] => 'messages.videos.save_removed',
        };
    }

    private function interactionNotification(string $actorName, string $type, Video $video): array
    {
        [$titleKey, $bodyKey] = match ($type) {
            'like' => ['video_like_title', 'video_like_body'],
            'dislike' => ['video_dislike_title', 'video_dislike_body'],
        };

        return [
            'messages.notifications.'.$titleKey,
            'messages.notifications.'.$bodyKey,
            ['name' => $actorName],
            ['videoId' => $video->id],
        ];
    }

}