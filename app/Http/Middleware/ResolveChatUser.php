<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveChatUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1) Already authenticated via token — pass through normally
        if (Auth::guard('sanctum')->check()) {
            Auth::shouldUse('sanctum');
            return $next($request);
        }

        // 2) Try guest_uuid from header
        $guestUuid = $request->header('guest_uuid');

        if (empty($guestUuid)) {
            return response()->json([
                'message' => 'يجب تسجيل الدخول أو تقديم guest_uuid صالح.',
            ], 401);
        }

        $user = User::where('guest_uuid', $guestUuid)->first();

        if (!$user) {
            return response()->json([
                'message' => 'لم يتم العثور على المستخدم الضيف. يرجى التحقق من guest_uuid.',
            ], 401);
        }

        // 3) Bind the guest user so $request->user() works in controllers
        Auth::guard('sanctum')->setUser($user);
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
