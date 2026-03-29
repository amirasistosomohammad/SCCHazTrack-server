<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Mail\OtpCodeMail;
use App\Mail\ResetPasswordLinkMail;
use App\Models\User;

class AuthController extends Controller
{
    private const EMAIL_OTP_TTL_MINUTES = 10;
    private const RESET_OTP_TTL_MINUTES = 10;
    private const RESET_TOKEN_TTL_MINUTES = 15;
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim((string) $credentials['email']));
        $password = (string) $credentials['password'];

        /** @var \App\Models\User|null $user */
        $user = User::query()->where('email', $email)->first();
        if (! $user || ! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account is inactive.',
                'accountStatus' => 'deactivated',
                'deactivation_remarks' => $user->deactivation_remarks,
            ], 403);
        }

        if (! $user->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email address to continue.',
                'code' => 'EMAIL_NOT_VERIFIED',
            ], 403);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'edp_number' => $user->edp_number,
                'campus' => $user->campus,
                'section_unit' => $user->section_unit,
                'designation_position' => $user->designation_position,
                'role' => $user->role,
                'department' => $user->department,
                'phone' => $user->phone,
            ],
            'token' => $token,
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'edp_number' => ['required', 'string', 'max:64'],
            'campus' => ['required', 'string', 'max:255'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            if (! $existing->email_verified_at) {
                $this->issueEmailOtp($email, $existing->name);
                return response()->json([
                    'ok' => true,
                    'requiresEmailVerification' => true,
                    'email' => $email,
                    'message' => 'We sent a verification code to your email.',
                ]);
            }

            return response()->json([
                'message' => 'An account with this email already exists.',
                'code' => 'EMAIL_EXISTS',
            ], 422);
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $email,
            'password' => $data['password'],
            'role' => User::ROLE_REPORTER,
            'department' => null,
            'phone' => null,
            'is_active' => true,
            'edp_number' => $data['edp_number'] ?? null,
            'campus' => $data['campus'] ?? null,
        ]);

        $this->issueEmailOtp($email, $user->name);

        return response()->json([
            'ok' => true,
            'requiresEmailVerification' => true,
            'email' => $email,
            'message' => 'We sent a verification code to your email.',
        ]);
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $otp = preg_replace('/\D+/', '', (string) $data['otp']);

        $cacheKey = $this->emailOtpCacheKey($email);
        $payload = Cache::get($cacheKey);
        if (! is_array($payload) || empty($payload['hash'])) {
            return response()->json([
                'message' => 'The verification code has expired. Please request a new code.',
                'code' => 'OTP_EXPIRED',
            ], 422);
        }

        $attempts = (int) ($payload['attempts'] ?? 0);
        if ($attempts >= 8) {
            Cache::forget($cacheKey);
            return response()->json([
                'message' => 'Too many attempts. Please request a new code.',
                'code' => 'OTP_TOO_MANY_ATTEMPTS',
            ], 429);
        }

        if (! Hash::check($otp, (string) $payload['hash'])) {
            $payload['attempts'] = $attempts + 1;
            Cache::put($cacheKey, $payload, now()->addMinutes(self::EMAIL_OTP_TTL_MINUTES));
            return response()->json([
                'message' => 'Invalid verification code.',
                'code' => 'OTP_INVALID',
            ], 422);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            Cache::forget($cacheKey);
            return response()->json([
                'message' => 'Account not found.',
                'code' => 'ACCOUNT_NOT_FOUND',
            ], 404);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        Cache::forget($cacheKey);

        if (! $user->is_active) {
            return response()->json([
                'ok' => true,
                'message' => 'Email verified successfully. Your account is not active yet.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 200);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'ok' => true,
            'message' => 'Email verified successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'edp_number' => $user->edp_number,
                'campus' => $user->campus,
                'section_unit' => $user->section_unit,
                'designation_position' => $user->designation_position,
                'role' => $user->role,
                'department' => $user->department,
                'phone' => $user->phone,
            ],
            'token' => $token,
        ]);
    }

    public function resendOtp(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'purpose' => ['nullable', 'string'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $purpose = $data['purpose'] === 'password_reset' ? 'password_reset' : 'email_verify';

        $cooldownKey = $this->resendCooldownKey($purpose, $email);
        if (Cache::has($cooldownKey)) {
            return response()->json([
                'message' => 'Please wait a moment before requesting another code.',
                'code' => 'OTP_RESEND_COOLDOWN',
            ], 429);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return response()->json([
                'message' => 'Account not found.',
                'code' => 'ACCOUNT_NOT_FOUND',
            ], 404);
        }

        if ($purpose === 'password_reset') {
            $this->issueResetOtp($email, $user->name);
        } else {
            if ($user->email_verified_at) {
                return response()->json([
                    'message' => 'Email is already verified.',
                    'code' => 'EMAIL_ALREADY_VERIFIED',
                ], 422);
            }
            $this->issueEmailOtp($email, $user->name);
        }

        Cache::put($cooldownKey, true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));

        return response()->json([
            'ok' => true,
            'message' => 'A new code has been sent.',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            // Avoid leaking whether an email exists; still return ok.
            return response()->json([
                'ok' => true,
                'message' => 'If that email exists, we sent a reset link.',
            ]);
        }

        // Only verified emails can receive password reset links.
        // Still return a generic response to avoid leaking account status.
        if (! $user->email_verified_at) {
            return response()->json([
                'ok' => true,
                'message' => 'If that email exists, we sent a reset link.',
            ]);
        }

        $token = Str::random(64);
        Cache::put(
            $this->resetTokenCacheKey($token),
            ['email' => $email],
            now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES)
        );

        $frontend = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $resetUrl = $frontend.'/reset-password?token='.$token;
        $recipientName = $user->name;
        $resetExpiresMinutes = self::RESET_TOKEN_TTL_MINUTES;

        dispatch(function () use ($email, $recipientName, $resetUrl, $resetExpiresMinutes) {
            try {
                Mail::to($email)->send(new ResetPasswordLinkMail(
                    appName: config('app.name', 'SCC HazTrack'),
                    recipientName: $recipientName,
                    resetUrl: $resetUrl,
                    expiresMinutes: $resetExpiresMinutes,
                ));
            } catch (\Throwable $e) {
                logger()->warning('Password reset email delivery failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();

        return response()->json([
            'ok' => true,
            'message' => 'If that email exists, we sent a reset link.',
        ]);
    }

    public function verifyResetOtp(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $otp = preg_replace('/\D+/', '', (string) $data['otp']);

        $cacheKey = $this->resetOtpCacheKey($email);
        $payload = Cache::get($cacheKey);
        if (! is_array($payload) || empty($payload['hash'])) {
            return response()->json([
                'message' => 'The reset code has expired. Please request a new code.',
                'code' => 'OTP_EXPIRED',
            ], 422);
        }

        $attempts = (int) ($payload['attempts'] ?? 0);
        if ($attempts >= 8) {
            Cache::forget($cacheKey);
            return response()->json([
                'message' => 'Too many attempts. Please request a new code.',
                'code' => 'OTP_TOO_MANY_ATTEMPTS',
            ], 429);
        }

        if (! Hash::check($otp, (string) $payload['hash'])) {
            $payload['attempts'] = $attempts + 1;
            Cache::put($cacheKey, $payload, now()->addMinutes(self::RESET_OTP_TTL_MINUTES));
            return response()->json([
                'message' => 'Invalid reset code.',
                'code' => 'OTP_INVALID',
            ], 422);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            Cache::forget($cacheKey);
            return response()->json([
                'message' => 'Account not found.',
                'code' => 'ACCOUNT_NOT_FOUND',
            ], 404);
        }

        $token = Str::random(64);
        Cache::put($this->resetTokenCacheKey($token), ['email' => $email], now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES));
        Cache::forget($cacheKey);

        return response()->json([
            'ok' => true,
            'resetToken' => $token,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'resetToken' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $token = (string) $data['resetToken'];
        $tokenPayload = Cache::get($this->resetTokenCacheKey($token));
        if (! is_array($tokenPayload) || empty($tokenPayload['email'])) {
            return response()->json([
                'message' => 'Reset link expired. Please request a new password reset.',
                'code' => 'RESET_TOKEN_EXPIRED',
            ], 422);
        }

        $email = (string) $tokenPayload['email'];
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            Cache::forget($this->resetTokenCacheKey($token));
            return response()->json([
                'message' => 'Account not found.',
                'code' => 'ACCOUNT_NOT_FOUND',
            ], 404);
        }

        $user->forceFill(['password' => $data['password']])->save();
        Cache::forget($this->resetTokenCacheKey($token));

        return response()->json([
            'ok' => true,
            'message' => 'Password updated successfully. You can now sign in.',
        ]);
    }

    public function logout(Request $request)
    {
        // We authenticate API calls via Sanctum personal access tokens (Bearer).
        // Revoke the current token instead of touching Laravel session state.
        $user = $request->user();
        $accessToken = $user?->currentAccessToken();
        if ($accessToken) {
            $accessToken->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        return response()->json([
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'edp_number' => $user->edp_number,
                'campus' => $user->campus,
                'section_unit' => $user->section_unit,
                'designation_position' => $user->designation_position,
                'role' => $user->role,
                'department' => $user->department,
                'phone' => $user->phone,
            ] : null,
        ]);
    }

    public function updateProfile(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'edp_number' => ['required', 'string', 'max:64'],
            'campus' => ['required', 'string', 'max:255'],
        ]);

        $user->forceFill([
            'name' => trim((string) $data['name']),
            'edp_number' => trim((string) $data['edp_number']),
            'campus' => trim((string) $data['campus']),
        ])->save();

        return response()->json([
            'ok' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'edp_number' => $user->edp_number,
                'campus' => $user->campus,
                'section_unit' => $user->section_unit,
                'designation_position' => $user->designation_position,
                'role' => $user->role,
                'department' => $user->department,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8'],
            'new_password_confirmation' => ['required', 'string', 'same:new_password'],
        ]);

        if (! Hash::check((string) $data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => (string) $data['new_password'],
        ])->save();

        return response()->json([
            'ok' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    private function issueEmailOtp(string $email, string $name): void
    {
        $otp = (string) random_int(100000, 999999);
        Cache::put(
            $this->emailOtpCacheKey($email),
            ['hash' => Hash::make($otp), 'attempts' => 0],
            now()->addMinutes(self::EMAIL_OTP_TTL_MINUTES)
        );

        $expiresMinutes = self::EMAIL_OTP_TTL_MINUTES;

        dispatch(function () use ($email, $name, $otp, $expiresMinutes) {
            try {
                Mail::to($email)->send(new OtpCodeMail(
                    appName: config('app.name', 'SCC HazTrack'),
                    recipientName: $name,
                    code: $otp,
                    purpose: 'email_verify',
                    expiresMinutes: $expiresMinutes
                ));
            } catch (\Throwable $e) {
                logger()->warning('Email OTP delivery failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    private function issueResetOtp(string $email, string $name): void
    {
        $otp = (string) random_int(100000, 999999);
        Cache::put(
            $this->resetOtpCacheKey($email),
            ['hash' => Hash::make($otp), 'attempts' => 0],
            now()->addMinutes(self::RESET_OTP_TTL_MINUTES)
        );

        $expiresMinutes = self::RESET_OTP_TTL_MINUTES;

        dispatch(function () use ($email, $name, $otp, $expiresMinutes) {
            try {
                Mail::to($email)->send(new OtpCodeMail(
                    appName: config('app.name', 'SCC HazTrack'),
                    recipientName: $name,
                    code: $otp,
                    purpose: 'password_reset',
                    expiresMinutes: $expiresMinutes
                ));
            } catch (\Throwable $e) {
                logger()->warning('Password reset OTP delivery failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    private function emailOtpCacheKey(string $email): string
    {
        return 'auth:email_otp:'.hash('sha256', $email);
    }

    private function resetOtpCacheKey(string $email): string
    {
        return 'auth:reset_otp:'.hash('sha256', $email);
    }

    private function resetTokenCacheKey(string $token): string
    {
        return 'auth:reset_token:'.hash('sha256', $token);
    }

    private function resendCooldownKey(string $purpose, string $email): string
    {
        return 'auth:otp_cooldown:'.$purpose.':'.hash('sha256', $email);
    }
}

