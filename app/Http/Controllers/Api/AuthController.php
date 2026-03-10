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

        if ($existingUser) {
            // LOGIN FLOW - works for both phone and email

            if ($existingUser->status !== 'active') {
                return response()->json(['message' => 'User is inactive. Please contact support.'], 403);
            }

            if (!Hash::check($data['password'], $existingUser->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            if (!empty($data['referral_code']) && $existingUser->referral_code !== $data['referral_code']) {
                return response()->json(['message' => 'Invalid referral code'], 401);
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

            if (!empty($data['referral_code'])) {
                // Check if referral_code is a valid user ID who is a representative
                // UPDATED: Now checks is_representative flag instead of role
                $delegateUser = User::where('id', $data['referral_code'])
                    ->where('is_representative', true)
                    ->first();

                if (!$delegateUser) {
                    return response([
                        'message' => 'Invalid delegate code. Please check the code and try again.'
                    ], 404);
                }
            }

            $user = User::create([
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'],
                'role' => 'user',
                'password' => Hash::make($data['password']),
                'country_code' => $data['country_code'] ?? null,
                'referral_code' => $data['referral_code'] ?? null,
            ]);

            // If user registered with a delegate code, add them to the delegate's clients list
            if (!empty($data['referral_code'])) {
                $userClient = UserClient::firstOrCreate(
                    ['user_id' => $data['referral_code']],
                    ['clients' => []]
                );

                $clients = $userClient->clients ?? [];

                // Check if user is already in the list (shouldn't happen, but just in case)
                if (!in_array($user->id, $clients)) {
                    $clients[] = $user->id;
                    $userClient->clients = $clients;
                    $userClient->save();
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
    public function changePass(User $user)
    {
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
