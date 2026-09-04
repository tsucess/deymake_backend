<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\WaitlistEntry;
use App\Support\PaginatedJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin waitlist controller.
 *
 * Operator listing / status flip / delete / CSV export for pre-launch
 * waitlist signups captured by WaitlistController.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/Waitlist.jsx.
 * Related: WaitlistEntry model, WaitlistEntryResource, WaitlistController.
 */
class AdminWaitlistController extends Controller
{
    private const STATUSES = ['pending', 'invited', 'converted'];

    public function index(Request $request): JsonResponse
    {
        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $entries = PaginatedJson::paginate(
            WaitlistEntry::query()
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('email', 'like', '%'.$query.'%')
                            ->orWhere('full_name', 'like', '%'.$query.'%')
                            ->orWhere('country', 'like', '%'.$query.'%');
                    });
                })
                ->when(in_array($status, self::STATUSES, true), fn (Builder $b) => $b->where('status', $status))
                ->when($sort === 'oldest', fn (Builder $b) => $b->oldest())
                ->when($sort !== 'oldest', fn (Builder $b) => $b->latest()),
            $request,
            20,
            100
        );

        return response()->json([
            'message' => __('messages.admin.waitlist_retrieved'),
            'data' => [
                'entries' => PaginatedJson::items($request, $entries, WaitlistEntryResource::class),
            ],
            'meta' => [
                'entries' => PaginatedJson::meta($entries),
                'summary' => [
                    'total' => WaitlistEntry::query()->count(),
                    'pending' => WaitlistEntry::query()->where('status', 'pending')->count(),
                    'invited' => WaitlistEntry::query()->where('status', 'invited')->count(),
                    'converted' => WaitlistEntry::query()->where('status', 'converted')->count(),
                ],
            ],
        ]);
    }

    public function update(Request $request, WaitlistEntry $waitlistEntry): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $waitlistEntry->forceFill(['status' => $validated['status']])->save();

        return response()->json([
            'message' => __('messages.admin.waitlist_updated'),
            'data' => [
                'entry' => new WaitlistEntryResource($waitlistEntry->fresh()),
            ],
        ]);
    }

    public function destroy(WaitlistEntry $waitlistEntry): JsonResponse
    {
        $waitlistEntry->delete();

        return response()->json([
            'message' => __('messages.admin.waitlist_deleted'),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $status = trim($request->string('status')->toString());
        $query = trim($request->string('q')->toString());

        $filename = 'waitlist-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($status, $query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'ID', 'Full Name', 'Email', 'Phone', 'Country',
                'Describes', 'Love To See', 'Agreed To Contact', 'Status', 'Joined At',
            ]);

            WaitlistEntry::query()
                ->when(in_array($status, self::STATUSES, true), fn (Builder $b) => $b->where('status', $status))
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('email', 'like', '%'.$query.'%')
                            ->orWhere('full_name', 'like', '%'.$query.'%')
                            ->orWhere('country', 'like', '%'.$query.'%');
                    });
                })
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($handle): void {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->id,
                            $row->full_name,
                            $row->email,
                            $row->phone,
                            $row->country,
                            $row->describes,
                            $row->love_to_see,
                            $row->agreed_to_contact ? 'yes' : 'no',
                            $row->status,
                            optional($row->created_at)->toIso8601String(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
