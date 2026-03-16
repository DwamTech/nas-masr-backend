<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        // Try to find existing user by phone first, then by email
        $existingUser = null;
        
        if (!empty($data['phone'])) {
            $existingUser = User::where('phone', $data['phone'])->first();
        }
        
        if (!$existingUser && !empty($data['email'])) {
            $existingUser = User::where('email', $data['email'])->first();
        }

        // Validate referral_code if provided
        $agentClient = null;
        if (!empty($data['referral_code'])) {
            $agentClient = UserClient::where('user_id', $data['referral_code'])->first();
            
            if (!$agentClient) {
                return response()->json([
                    'message' => 'كود الإحالة غير صحيح. يرجى التحقق من الكود والمحاولة مرة أخرى.'
                ], 404);
            }
        }

        if ($existingUser) {
            // LOGIN FLOW - works for both phone and email

            if ($existingUser->status !== 'active') {
                return response()->json(['message' => 'User is inactive. Please contact support.'], 403);
            }

            if (!Hash::check($data['password'], $existingUser->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            // Update referral_code if provided during login
            if ($agentClient && $existingUser->referral_code !== $data['referral_code']) {
                $existingUser->referral_code = $agentClient->user_id;
                $existingUser->save();
            }

            $message = "User logged in successfully.";
            $user = $existingUser;
        } else {
            // REGISTRATION FLOW - requires phone number

            if (empty($data['phone'])) {
                return response()->json([
                    'message' => 'Phone number is required for registration. Email can only be used for login.'
                ], 422);
            }

            $user = User::create([
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'],
                'role' => 'user',
                'password' => Hash::make($data['password']),
                'country_code' => $data['country_code'] ?? null,
                'referral_code' => $agentClient?->user_id ?? null,
            ]);

            // If user registered with a referral code, add them to the agent's clients list
            if ($agentClient) {
                $clients = $agentClient->clients ?? [];

                // Add user to clients list if not already present
                if (!in_array($user->id, $clients)) {
                    $clients[] = $user->id;
                    $agentClient->clients = $clients;
                    $agentClient->save();
                }
            }

            $message = "User registered successfully.";
        }

        $token = $user->createToken('nasmasr_token')->plainTextToken;

        return response()->json([
            'message' => $message,
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }


    // admin change  password
    public function changePass(Request $request, User $user)
    {
        $actor = $request->user();

        if ($actor && !$actor->isAdmin() && $user->isPrivilegedDashboardRole()) {
            return response()->json([
                'message' => 'غير مصرح لك بتعديل حسابات فريق الداشبورد.',
            ], 403);
        }

        $user->password = Hash::make('123456');

        $user->save();
        return response()->json([
            'message' => 'مرحبًا ' . $user->name . '، تم تغيير كلمة السر الخاصة بحسابك إلى: 123456. يرجى تسجيل الدخول وتغييرها بعد أول دخول. فريق ناس مصر',
        ]);
    }

    // admin change own password using current password
    public function changeOwnPassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $admin = $request->user();

        if (!$admin || strtolower((string) $admin->role) !== 'admin') {
            return response()->json([
                'message' => 'غير مصرح لك بتنفيذ هذا الإجراء',
            ], 403);
        }

        if (!Hash::check($data['current_password'], $admin->password)) {
            return response()->json([
                'message' => 'كلمة المرور الحالية غير صحيحة',
                'errors' => [
                    'current_password' => ['كلمة المرور الحالية غير صحيحة'],
                ],
            ], 422);
        }

        if (Hash::check($data['new_password'], $admin->password)) {
            return response()->json([
                'message' => 'كلمة المرور الجديدة يجب أن تختلف عن الحالية',
                'errors' => [
                    'new_password' => ['كلمة المرور الجديدة يجب أن تختلف عن الحالية'],
                ],
            ], 422);
        }

        $admin->password = Hash::make($data['new_password']);
        $admin->save();

        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح',
        ]);
    }
}
