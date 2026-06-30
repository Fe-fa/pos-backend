<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return response()->json([
            'message' => 'Registration successful.',
            'user' => $this->authService->profile($user),
        ], 201);
    }

public function login(LoginRequest $request): JsonResponse
{
    $result = $this->authService->login($request->validated());

    if (! $result['user']['email_verified']) {
        return response()->json([
            'message'              => 'Email not verified.',
            'requires_verification'=> true,
            'access_token'         => $result['access_token'],
            'token_type'           => $result['token_type'],
            'user'                 => $result['user'],
        ], 403);
    }

    return response()->json([
        'message'      => 'Login successful.',
        'token_type'   => $result['token_type'],
        'access_token' => $result['access_token'],
        'expires_at'   => $result['expires_at'],
        'refresh_expires_at' => $result['refresh_expires_at'],
        'user'         => $result['user'],
    ]);
}

    public function refresh(Request $request): JsonResponse
    {
        $result = $this->authService->refresh($request->user());

        return response()->json([
            'message' => 'Token refreshed.',
            'token_type' => $result['token_type'],
            'access_token' => $result['access_token'],
            'expires_at' => $result['expires_at'],
            'refresh_expires_at' => $result['refresh_expires_at'],
            'user' => $result['user'],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Authenticated user profile.',
            'user' => $this->authService->profile($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());
        return response()->json(['message' => 'Logout successful.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());
        return response()->json(['message' => 'Logged out from all devices.']);
    }

public function verifyEmail(Request $request): JsonResponse
{
    $request->validate(['code' => ['required', 'string', 'digits:6']]);

    $this->authService->verifyEmailCode($request->user(), $request->input('code'));

    return response()->json(['message' => 'Email verified successfully.']);
}

public function resendVerification(Request $request): JsonResponse
{
    $this->authService->resendVerificationCode($request->user());

    return response()->json(['message' => 'Verification code sent to your email.']);
}

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Password reset link sent.'])
            : response()->json(['message' => 'Unable to send reset link.'], 400);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password reset successful.'])
            : response()->json(['message' => 'Password reset failed.'], 400);
    }

    public function sessions(Request $request): JsonResponse
    {
        $sessions = $this->authService->getSessions($request->user());

        return response()->json([
            'message'  => 'Active sessions retrieved.',
            'sessions' => $sessions,
        ]);
    }

    public function revokeSession(Request $request, string $sessionId): JsonResponse
    {
        $revoked = $this->authService->revokeSession($request->user(), $sessionId);

        return $revoked
            ? response()->json(['message' => 'Session revoked successfully.'])
            : response()->json(['message' => 'Session not found.'], 404);
    }

    public function revokeAllSessions(Request $request): JsonResponse
    {
        $this->authService->revokeAllSessions($request->user());

        return response()->json(['message' => 'All sessions revoked.']);
    }
}