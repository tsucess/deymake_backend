<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin live stream controller.
 *
 * Admin actions on live streams: list active/recent lives, force-stop a
 * runaway session when the creator is gone or unreachable.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/LiveStream.jsx.
 * Related: VideoController (creator-side live lifecycle), EnsureAdmin middleware.
 * See PROJECT_OVERVIEW.md §3.27 for the full data-flow map.
 */
class AdminLiveStreamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $streams = PaginatedJson::paginate(
            $this->liveQuery($request->user())
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $searchQuery) use ($query): void {
                        $searchQuery
                            ->where('title', 'like', '%'.$query.'%')
                            ->orWhere('public_id', 'like', '%'.$query.'%')
                            ->orWhereHas('user', function (Builder $userQuery) use ($query): void {
                                $userQuery
                                    ->where('name', 'like', '%'.$query.'%')
                                    ->orWhere('username', 'like', '%'.$query.'%');
                            });
                    });
                })
                ->when($status === 'live', fn (Builder $builder) => $builder->where('is_live', true))
                ->when($status === 'ended', fn (Builder $builder) => $builder->where('is_live', false)->whereNotNull('live_ended_at'))
                ->when($sort === 'oldest', fn (Builder $builder) => $builder->oldest('live_started_at'))
                ->when($sort !== 'oldest', fn (Builder $builder) => $builder->latest('live_started_at')),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.live_streams_retrieved'),
            'data' => [
                'liveStreams' => PaginatedJson::items($request, $streams, VideoResource::class),
            ],
            'meta' => [
                'liveStreams' => PaginatedJson::meta($streams),
                'summary' => [
                    'liveNow' => Video::query()->where('is_live', true)->count(),
                    'endedTotal' => Video::query()->where('is_live', false)->whereNotNull('live_ended_at')->count(),
                ],
            ],
        ]);
    }

    public function stop(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if(! $video->is_live, 409, __('messages.videos.live_not_active'));

        $video->liveSignals()->delete();
        $video->livePresenceSessions()->update([
            'left_at' => now(),
        ]);

        $video->forceFill([
            'is_live' => false,
            'is_draft' => true,
            'live_ended_at' => now(),
            'live_notified_at' => null,
        ])->save();

        $video->load(['user' => fn ($q) => $q->withProfileAggregates($request->user()), 'category', 'upload']);

        return response()->json([
            'message' => __('messages.admin.live_stream_stopped'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    private function liveQuery($viewer): Builder
    {
        return Video::query()
            ->where(function (Builder $builder): void {
                $builder->where('is_live', true)->orWhereNotNull('live_ended_at');
            })
            ->with([
                'user' => fn ($q) => $q->withProfileAggregates($viewer),
                'category',
                'upload',
            ]);
    }
}
