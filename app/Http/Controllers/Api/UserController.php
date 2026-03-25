<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'message' => 'User profile retrieved successfully.',
            'data' => [
                'user' => new ProfileResource($user),
            ],
        ]);
    }

    public function posts(User $user): JsonResponse
    {
        $videos = Video::query()
            ->with(['user', 'category', 'upload'])
            ->where('user_id', $user->id)
            ->where('is_draft', false)
            ->latest()
            ->get();

        return response()->json([
            'message' => 'User posts retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($videos),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->string('q')->toString();

        $users = User::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('email', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'data' => [
                'users' => ProfileResource::collection($users),
            ],
        ]);
    }
}