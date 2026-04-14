<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateManagedUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $accountStatus = trim($request->string('accountStatus')->toString());
        $role = trim($request->string('role')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $users = PaginatedJson::paginate(
            $this->userQuery()
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $searchQuery) use ($query): void {
                        $searchQuery
                            ->where('name', 'like', '%'.$query.'%')
                            ->orWhere('username', 'like', '%'.$query.'%')
                            ->orWhere('email', 'like', '%'.$query.'%');
                    });
                })
                ->when(in_array($accountStatus, ['active', 'suspended'], true), fn (Builder $builder) => $builder->where('account_status', $accountStatus))
                ->when($role === 'admin', fn (Builder $builder) => $builder->where('is_admin', true))
                ->when($role === 'creator', fn (Builder $builder) => $builder->has('videos'))
                ->when($role === 'member', fn (Builder $builder) => $builder->where('is_admin', false)->doesntHave('videos'))
                ->when($sort === 'oldest', fn (Builder $builder) => $builder->oldest())
                ->when($sort === 'active', fn (Builder $builder) => $builder->orderByDesc('last_active_at')->latest())
                ->when(! in_array($sort, ['oldest', 'active'], true), fn (Builder $builder) => $builder->latest()),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.users_retrieved'),
            'data' => [
                'users' => PaginatedJson::items($request, $users, AdminUserResource::class),
            ],
            'meta' => [
                'users' => PaginatedJson::meta($users),
                'summary' => [
                    'totalUsers' => User::query()->count(),
                    'adminUsers' => User::query()->where('is_admin', true)->count(),
                    'suspendedUsers' => User::query()->where('account_status', 'suspended')->count(),
                    'creatorUsers' => User::query()->has('videos')->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $user->loadCount($this->managementCounts());

        return response()->json([
            'message' => __('messages.admin.user_retrieved'),
            'data' => [
                'user' => new AdminUserResource($user),
            ],
        ]);
    }

    public function update(UpdateManagedUserRequest $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $currentAdmin = $request->user();
        $isSelf = $currentAdmin->is($user);
        $nextStatus = $validated['accountStatus'] ?? $user->accountStatus();
        $nextIsAdmin = array_key_exists('isAdmin', $validated) ? (bool) $validated['isAdmin'] : $user->isAdmin();

        abort_if(
            $isSelf && ($nextStatus === 'suspended' || ! $nextIsAdmin),
            422,
            __('messages.admin.user_self_protection')
        );

        $user->forceFill([
            'is_admin' => $nextIsAdmin,
            'account_status' => $nextStatus,
            'account_status_notes' => $validated['accountStatusNotes'] ?? $user->account_status_notes,
            'suspended_at' => $nextStatus === 'suspended' ? now() : null,
            'suspended_by' => $nextStatus === 'suspended' ? $currentAdmin->id : null,
            'is_online' => $nextStatus === 'suspended' ? false : $user->is_online,
        ])->save();

        if (($validated['clearSessions'] ?? false) || $nextStatus === 'suspended') {
            $user->tokens()->delete();
        }

        $user->loadCount($this->managementCounts());

        return response()->json([
            'message' => __('messages.admin.user_updated'),
            'data' => [
                'user' => new AdminUserResource($user),
            ],
        ]);
    }

    /**
     * @return array<int, string|array<string, \Closure>>
     */
    private function managementCounts(): array
    {
        return [
            'videos',
            'subscribers',
            'videoReports',
            'challengeSubmissions',
            'videos as published_videos_count' => fn (Builder $builder) => $builder->where('is_draft', false),
            'videos as live_videos_count' => fn (Builder $builder) => $builder->where('is_live', true),
        ];
    }

    private function userQuery(): Builder
    {
        return User::query()->withCount($this->managementCounts());
    }
}