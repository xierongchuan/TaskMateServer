<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SessionController extends Controller
{
    use ApiResponses;

    /**
     * Maximum failed login attempts before account lockout
     */
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Lockout duration in minutes
     */
    private const LOCKOUT_MINUTES = 15;

    public function store(LoginRequest $req)
    {
        Log::info('Login attempt', ['login' => $req->login]);

        try {
            $user = User::where('login', $req->login)->first();
        } catch (\Exception $e) {
            Log::error('Login DB Error', ['error' => $e->getMessage()]);

            return $this->errorResponse('Ошибка базы данных', 500);
        }

        // Check if account is locked
        if ($user && $user->locked_until && $user->locked_until > now()) {
            $minutesLeft = (int) now()->diffInMinutes($user->locked_until, false);
            Log::warning('Login blocked: Account locked', [
                'login' => $req->login,
                'locked_until' => $user->locked_until,
            ]);

            return $this->errorResponse("Аккаунт временно заблокирован. Попробуйте через {$minutesLeft} мин.", 429);
        }

        if (! $user || ! Hash::check($req->password, $user->password)) {
            // Track failed login attempts
            if ($user) {
                $user->increment('failed_login_attempts');
                $user->last_failed_login_at = now();

                // Lock account after exceeding max attempts
                if ($user->failed_login_attempts >= self::MAX_FAILED_ATTEMPTS) {
                    $user->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
                    Log::warning('Account locked due to failed attempts', [
                        'user_id' => $user->id,
                        'attempts' => $user->failed_login_attempts,
                    ]);
                }

                $user->save();
            }

            Log::warning('Login failed: Invalid credentials', ['login' => $req->login]);

            return $this->errorResponse('Неверные данные', 401);
        }

        // Reset failed attempts on successful login
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login_at' => null,
        ]);

        $token = $user->createToken($req->userAgent() ?? 'unknown-device')->plainTextToken;
        Log::info('Login successful', ['user_id' => $user->id]);

        $user->load(['dealership', 'dealerships']);

        return $this->successResponse([
            'token' => $token,
            'user' => UserResource::make($user)->resolve(),
        ]);
    }

    public function destroy(Request $request)
    {
        Log::info('Logout initiated', ['user_id' => $request->user()->id]);
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Сессия завершена');
    }

    public function current(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            Log::warning('Session check failed: No user found from token');

            return $this->errorResponse('Не авторизован', 401);
        }

        $user->load(['dealership', 'dealerships']);

        Log::info('Session check successful', ['user_id' => $user->id]);

        return $this->successResponse(['user' => UserResource::make($user)->resolve()]);
    }
}
