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
        abort_if($creator->is($request->user()), 422, 'You cannot subscribe to yourself.');

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

        UserNotifier::send($creator->id, $request->user()->id, 'subscription', 'New subscriber', $request->user()->name.' subscribed to your profile.', [
            'creatorId' => $creator->id,
        ]);

        $creator->loadCount('subscribers');

        return response()->json([
            'message' => 'Creator subscribed successfully.',
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
            'message' => 'Creator unsubscribed successfully.',
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
                UserNotifier::send(
                    $video->user_id,
                    $request->user()->id,
                    'video_'.$type,
                    ucfirst($type).' on your video',
                    $request->user()->name.' '.$type.'d your video.',
                    ['videoId' => $video->id]
                );
            }
        } else {
            $query->delete();
        }

        $video = Video::query()
            ->withApiResourceData($request->user())
            ->findOrFail($video->id);

        return response()->json([
            'message' => 'Video '.($active ? $type.'d' : $type.' removed').' successfully.',
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

}