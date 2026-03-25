<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\UserDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('fullName')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'preferences' => UserDefaults::preferences(),
            'is_online' => true,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'tokenType' => 'Bearer',
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ], 422);
        }

        $user->forceFill(['is_online' => true])->save();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'tokenType' => 'Bearer',
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Authenticated user retrieved successfully.',
            'data' => [
                'user' => new UserResource($request->user()),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['is_online' => false])->save();

        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        } else {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
        ]);

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $validated['email']],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Password reset token generated successfully.',
            'data' => [
                'email' => $validated['email'],
                'resetToken' => $token,
                'expiresInMinutes' => 60,
            ],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
        ]);

        $reset = DB::table('password_reset_tokens')->where('email', $validated['email'])->first();

        if (! $reset || now()->diffInMinutes($reset->created_at) > 60 || ! Hash::check($validated['token'], $reset->token)) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
                'errors' => [
                    'token' => ['The provided reset token is invalid or expired.'],
                ],
            ], 422);
        }

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
        $user->forceFill(['password' => $validated['password']])->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return response()->json([
            'message' => 'Password reset successful.',
            'data' => [
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function oauthRedirect(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['google', 'facebook'], true), 404);

        return response()->json([
            'message' => ucfirst($provider).' OAuth is not configured on this backend yet.',
            'data' => [
                'provider' => $provider,
                'configured' => false,
            ],
        ], 501);
    }

    public function oauthCallback(string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['google', 'facebook'], true), 404);

        return response()->json([
            'message' => ucfirst($provider).' OAuth callback is not configured on this backend yet.',
            'data' => [
                'provider' => $provider,
                'configured' => false,
            ],
        ], 501);
    }
}