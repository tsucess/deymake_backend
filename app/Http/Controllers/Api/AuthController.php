<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\SendEmailVerificationCode;
use App\Support\Username;
use App\Support\UserDefaults;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    private const OAUTH_PROVIDERS = ['google', 'facebook'];

    private const VERIFICATION_CODE_TTL_MINUTES = 10;

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('fullName')->toString(),
            'username' => Username::normalize(
                $request->string('username')->toString(),
                Str::before($request->string('email')->toString(), '@'),
            ),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'preferences' => UserDefaults::preferences(),
            'is_online' => false,
        ]);

        $this->issueVerificationCode($user);

        return $this->verificationRequiredResponse($user, __('messages.auth.verification_code_sent'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = trim($request->string('identifier')->toString());

        $user = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? User::query()->where('email', $identifier)->first()
            : User::query()->where('username', Username::normalize($identifier))->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json([
                'message' => __('messages.auth.invalid_credentials'),
                'errors' => [
                    'identifier' => [__('messages.auth.invalid_credentials_detail')],
                ],
            ], 422);
        }

        if (! $user->email_verified_at) {
            return response()->json([
                'message' => __('messages.auth.verification_required'),
            ], 403);
        }

        $user->forceFill(['is_online' => true])->save();

        return $this->authenticatedResponse($user, __('messages.auth.login_success'));
    }

    public function verifyEmailCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'code' => ['required', 'string', 'size:4'],
        ]);

        $user = User::query()->where('email', $validated['email'])->firstOrFail();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => __('messages.auth.already_verified'),
                'errors' => [
                    'email' => [__('messages.auth.already_verified_detail')],
                ],
            ], 422);
        }

        $verification = DB::table('email_verification_codes')
            ->where('email', $validated['email'])
            ->first();

        if (! $verification || ! hash_equals((string) $verification->code, $validated['code'])) {
            return response()->json([
                'message' => __('messages.auth.verification_code_invalid'),
                'errors' => [
                    'code' => [__('messages.auth.verification_code_invalid_detail')],
                ],
            ], 422);
        }

        if (Carbon::parse($verification->expires_at)->isPast()) {
            DB::table('email_verification_codes')->where('email', $validated['email'])->delete();

            return response()->json([
                'message' => __('messages.auth.verification_code_expired'),
                'errors' => [
                    'code' => [__('messages.auth.verification_code_expired_detail')],
                ],
            ], 422);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'is_online' => true,
        ])->save();

        DB::table('email_verification_codes')->where('email', $validated['email'])->delete();

        return $this->authenticatedResponse($user, __('messages.auth.email_verified'));
    }

    public function resendVerificationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
        ]);

        $user = User::query()->where('email', $validated['email'])->firstOrFail();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => __('messages.auth.already_verified'),
                'errors' => [
                    'email' => [__('messages.auth.already_verified_detail')],
                ],
            ], 422);
        }

        $this->issueVerificationCode($user);

        return response()->json([
            'message' => __('messages.auth.verification_code_resent'),
            'data' => [
                'email' => $user->email,
                'expiresInMinutes' => self::VERIFICATION_CODE_TTL_MINUTES,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('messages.auth.me_retrieved'),
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
            'message' => __('messages.auth.logout_success'),
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
            'message' => __('messages.auth.password_reset_token_generated'),
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
                'message' => __('messages.auth.reset_token_invalid'),
                'errors' => [
                    'token' => [__('messages.auth.reset_token_invalid_detail')],
                ],
            ], 422);
        }

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
        $user->forceFill(['password' => $validated['password']])->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return response()->json([
            'message' => __('messages.auth.password_reset_success'),
            'data' => [
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function oauthRedirect(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        if ($response = $this->ensureOauthProviderIsConfigured($request, $provider)) {
            return $response;
        }

        $state = Str::random(48);

        Cache::put($this->oauthStateCacheKey($provider, $state), true, now()->addMinutes(10));

        return redirect()->away($this->buildProviderAuthorizationUrl($provider, $state));
    }

    public function oauthCallback(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($provider, self::OAUTH_PROVIDERS, true), 404);

        if ($response = $this->ensureOauthProviderIsConfigured($request, $provider)) {
            return $response;
        }

        if ($request->filled('error')) {
            return $this->oauthErrorResponse(
                $request,
                $provider,
                (string) $request->query('error_description', $request->query('error')),
            );
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || ! Cache::pull($this->oauthStateCacheKey($provider, $state))) {
            return $this->oauthErrorResponse($request, $provider, __('messages.auth.oauth.invalid_state'));
        }

        if ($code === '') {
            return $this->oauthErrorResponse($request, $provider, __('messages.auth.oauth.missing_code'));
        }

        try {
            $token = $this->exchangeOauthCodeForAccessToken($provider, $code);
            $profile = $this->fetchOauthProfile($provider, $token);
            $user = $this->resolveOauthUser($provider, $profile);
            $authToken = $user->createToken('auth_token')->plainTextToken;

            return redirect()->away($this->buildFrontendCallbackUrl($provider, [
                'token' => $authToken,
            ]));
        } catch (Throwable $exception) {
            report($exception);

            return $this->oauthErrorResponse(
                $request,
                $provider,
                __('messages.auth.oauth.signin_failed', ['provider' => ucfirst($provider)]),
                502,
            );
        }
    }

    private function ensureOauthProviderIsConfigured(Request $request, string $provider): JsonResponse|RedirectResponse|null
    {
        $config = $this->oauthConfig($provider);

        if ($config['client_id'] !== '' && $config['client_secret'] !== '' && $config['redirect'] !== '') {
            return null;
        }

        return $this->oauthErrorResponse(
            $request,
            $provider,
            __('messages.auth.oauth.provider_not_configured', ['provider' => ucfirst($provider)]),
            503,
            false,
        );
    }

    private function oauthConfig(string $provider): array
    {
        return match ($provider) {
            'google' => [
                'client_id' => (string) config('services.google.client_id', ''),
                'client_secret' => (string) config('services.google.client_secret', ''),
                'redirect' => (string) config('services.google.redirect', ''),
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'profile_url' => 'https://www.googleapis.com/oauth2/v2/userinfo',
                'scope' => 'openid profile email',
            ],
            'facebook' => [
                'client_id' => (string) config('services.facebook.client_id', ''),
                'client_secret' => (string) config('services.facebook.client_secret', ''),
                'redirect' => (string) config('services.facebook.redirect', ''),
                'authorize_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
                'profile_url' => 'https://graph.facebook.com/me',
                'scope' => 'email,public_profile',
            ],
        };
    }

    private function buildProviderAuthorizationUrl(string $provider, string $state): string
    {
        $config = $this->oauthConfig($provider);

        $query = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'],
            'response_type' => 'code',
            'scope' => $config['scope'],
            'state' => $state,
        ];

        if ($provider === 'google') {
            $query['prompt'] = 'select_account';
        }

        return $config['authorize_url'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function exchangeOauthCodeForAccessToken(string $provider, string $code): string
    {
        $config = $this->oauthConfig($provider);

        $response = match ($provider) {
            'google' => Http::asForm()->acceptJson()->post($config['token_url'], [
                'code' => $code,
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $config['redirect'],
                'grant_type' => 'authorization_code',
            ]),
            'facebook' => Http::acceptJson()->get($config['token_url'], [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $config['redirect'],
                'code' => $code,
            ]),
        };

        $payload = $response->throw()->json();
        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException(__('messages.auth.oauth.missing_access_token'));
        }

        return $accessToken;
    }

    private function fetchOauthProfile(string $provider, string $accessToken): array
    {
        $config = $this->oauthConfig($provider);

        $payload = match ($provider) {
            'google' => Http::withToken($accessToken)->acceptJson()->get($config['profile_url'])->throw()->json(),
            'facebook' => Http::acceptJson()->get($config['profile_url'], [
                'fields' => 'id,name,email,picture.type(large)',
                'access_token' => $accessToken,
            ])->throw()->json(),
        };

        $providerId = (string) ($payload['id'] ?? '');
        $email = trim((string) ($payload['email'] ?? ''));

        if ($providerId === '') {
            throw new RuntimeException(__('messages.auth.oauth.missing_user_id'));
        }

        if ($email === '') {
            throw new RuntimeException(__('messages.auth.oauth.missing_email'));
        }

        return [
            'id' => $providerId,
            'email' => $email,
            'name' => trim((string) ($payload['name'] ?? Str::before($email, '@'))),
            'avatar_url' => $provider === 'google'
                ? Arr::get($payload, 'picture')
                : Arr::get($payload, 'picture.data.url'),
        ];
    }

    private function resolveOauthUser(string $provider, array $profile): User
    {
        $user = User::query()
            ->where('provider', $provider)
            ->where('provider_id', $profile['id'])
            ->first();

        if (! $user) {
            $user = User::query()->where('email', $profile['email'])->first();
        }

        if (! $user) {
            return User::create([
                'name' => $profile['name'],
                'username' => $this->uniqueUsernameFor($profile['name'], $profile['email']),
                'email' => $profile['email'],
                'email_verified_at' => now(),
                'password' => Str::password(32),
                'avatar_url' => $profile['avatar_url'] ?? null,
                'preferences' => UserDefaults::preferences(),
                'is_online' => true,
                'provider' => $provider,
                'provider_id' => $profile['id'],
            ]);
        }

        $updates = [
            'name' => $profile['name'] ?: $user->name,
            'avatar_url' => $profile['avatar_url'] ?: $user->avatar_url,
            'is_online' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ];

        if (! $user->username) {
            $updates['username'] = $this->uniqueUsernameFor($profile['name'], $profile['email'], $user);
        }

        if (! $user->provider || $user->provider === $provider) {
            $updates['provider'] = $provider;
            $updates['provider_id'] = $profile['id'];
        }

        $user->forceFill($updates)->save();

        return $user;
    }

    private function oauthErrorResponse(
        Request $request,
        string $provider,
        string $message,
        int $status = 422,
        bool $configured = true,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'data' => [
                    'provider' => $provider,
                    'configured' => $configured,
                ],
            ], $status);
        }

        return redirect()->away($this->buildFrontendCallbackUrl($provider, [
            'error' => $message,
        ]));
    }

    private function buildFrontendCallbackUrl(string $provider, array $fragment = []): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/').'/auth/callback';
        $fragmentString = http_build_query([
            'provider' => $provider,
            ...array_filter($fragment, static fn ($value) => $value !== null && $value !== ''),
        ], '', '&', PHP_QUERY_RFC3986);

        return $fragmentString === '' ? $baseUrl : $baseUrl.'#'.$fragmentString;
    }

    private function oauthStateCacheKey(string $provider, string $state): string
    {
        return 'oauth_state:'.$provider.':'.$state;
    }

    private function uniqueUsernameFor(string $name, string $email, ?User $ignoreUser = null): string
    {
        $fallback = Str::before($email, '@');

        return Username::unique(
            $name !== '' ? $name : $fallback,
            static fn (string $candidate): bool => User::query()
                ->when($ignoreUser, fn ($query) => $query->whereKeyNot($ignoreUser->id))
                ->where('username', $candidate)
                ->exists(),
            $fallback !== '' ? $fallback : 'user',
        );
    }

    private function authenticatedResponse(User $user, string $message, int $status = 200): JsonResponse
    {
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => $message,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'tokenType' => 'Bearer',
            ],
        ], $status);
    }

    private function verificationRequiredResponse(User $user, string $message, int $status = 202): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                'user' => new UserResource($user),
                'verification' => [
                    'required' => true,
                    'email' => $user->email,
                    'expiresInMinutes' => self::VERIFICATION_CODE_TTL_MINUTES,
                ],
            ],
        ], $status);
    }

    private function issueVerificationCode(User $user): void
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        DB::table('email_verification_codes')->updateOrInsert(
            ['email' => $user->email],
            [
                'user_id' => $user->id,
                'code' => $code,
                'expires_at' => now()->addMinutes(self::VERIFICATION_CODE_TTL_MINUTES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Notification::locale(app()->getLocale())
            ->send($user, new SendEmailVerificationCode($code, self::VERIFICATION_CODE_TTL_MINUTES));
    }
}