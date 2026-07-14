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
use App\Services\AuditLogService;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly AuditLogService $auditLogService
    ) {}

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
    $this->authService->forgotPassword($request->validated('email'));

    return response()->json(['message' => 'Password reset link sent.']);
}

public function resetPassword(ResetPasswordRequest $request): JsonResponse
{
    $this->authService->resetPassword(
        $request->validated('email'),
        $request->validated('token'),
        $request->validated('password'),
    );

    return response()->json(['message' => 'Password reset successful.']);
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