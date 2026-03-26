<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserConversation;
use App\Services\ChatService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * Get all support conversations (Unified Inbox for all admins).
     * GET /api/admin/support/inbox
     * 
     * Any admin can see all support conversations - Shared Inbox concept.
     */
    public function supportInbox(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        // Get all support conversations with latest message
        $conversations = UserConversation::support()
            ->with(['sender', 'receiver'])
            ->selectRaw('
                conversation_id,
                MAX(id) as last_message_id,
                MAX(created_at) as last_message_at,
                COUNT(*) as messages_count,
                SUM(CASE WHEN read_at IS NULL AND receiver_id IS NOT NULL THEN 1 ELSE 0 END) as unread_count
            ')
            ->groupBy('conversation_id')
            ->orderByDesc('last_message_at')
            ->paginate($perPage);

        // Get user details for each conversation
        $data = collect($conversations->items())->map(function ($conv) {
            // Get the first message to identify the user
            $firstMessage = UserConversation::where('conversation_id', $conv->conversation_id)
                ->orderBy('created_at', 'asc')
                ->first();

            // Get the last message
            $lastMessage = UserConversation::find($conv->last_message_id);

            // The user is the original sender of the support conversation
            $user = $firstMessage?->sender;

            return [
                'conversation_id' => $conv->conversation_id,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                ] : null,
                'last_message' => $lastMessage?->message,
                'last_message_at' => $conv->last_message_at,
                'last_message_by' => $lastMessage?->sender?->name,
                'messages_count' => $conv->messages_count,
                'unread_count' => $conv->unread_count,
            ];
        });

        return response()->json([
            'meta' => [
                'page' => $conversations->currentPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
                'last_page' => $conversations->lastPage(),
            ],
            'data' => $data,
        ]);
    }

    /**
     * Get support conversation history with a specific user.
     * GET /api/admin/support/{user}
     */
    public function supportHistory(Request $request, User $user): JsonResponse
    {
        $conversationId = $this->chatService->getConversationId($user, null, UserConversation::TYPE_SUPPORT);
        $perPage = (int) $request->query('per_page', 50);

        $messages = $this->chatService->getHistory($conversationId, $perPage);

        return response()->json([
            'meta' => [
                'conversation_id' => $conversationId,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                ],
                'page' => $messages->currentPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
                'last_page' => $messages->lastPage(),
            ],
            'data' => $messages->items(),
        ]);
    }

    /**
     * Admin reply to a user's support conversation.
     * POST /api/admin/support/reply
     * 
     * The reply is recorded with the admin's ID (for accountability)
     * but appears as "Support Team" to the user.
     */
    public function reply(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['required', 'string', 'max:5000'],
        ], [
            'user_id.required' => 'يجب تحديد المستخدم',
            'user_id.exists' => 'المستخدم غير موجود',
            'message.required' => 'يجب كتابة رسالة',
            'message.max' => 'الرسالة طويلة جداً',
        ]);

        $admin = $request->user();
        $user = User::findOrFail($data['user_id']);

        $conversation = $this->chatService->adminReplyToSupport($admin, $user, $data['message']);
        $notifications->dispatch(
            $user->id,
            'رسالة من الإدارة',
            $data['message'],
            'support_reply',
            [
                'conversation_id' => $conversation->conversation_id,
                'sender_id' => $admin->id,
                'sender_name' => $admin->name,
            ],
            false,
            NotificationService::SOURCE_ADMIN
        );

        return response()->json([
            'message' => 'تم إرسال الرد بنجاح',
            'data' => [
                'id' => $conversation->id,
                'conversation_id' => $conversation->conversation_id,
                'message' => $conversation->message,
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'created_at' => $conversation->created_at,
            ],
        ], 201);
    }

    /**
     * Mark all messages in a support conversation as read.
     * PATCH /api/admin/support/{user}/read
     */
    public function markAsRead(Request $request, User $user): JsonResponse
    {
        $conversationId = $this->chatService->getConversationId($user, null, UserConversation::TYPE_SUPPORT);
        
        // Mark messages sent by the user as read (admin reading user's messages)
        $count = UserConversation::inConversation($conversationId)
            ->where('sender_id', $user->id)
            ->where('sender_type', User::class)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'ok',
            'marked_count' => $count,
        ]);
    }

    /**
     * Get support statistics.
     * GET /api/admin/support/stats
     */
    public function stats(): JsonResponse
    {
        $totalConversations = UserConversation::support()
            ->distinct('conversation_id')
            ->count('conversation_id');

        $unreadConversations = UserConversation::support()
            ->whereNull('read_at')
            ->whereNull('receiver_id') // Messages from users to support
            ->distinct('conversation_id')
            ->count('conversation_id');

        $todayMessages = UserConversation::support()
            ->whereDate('created_at', today())
            ->count();

        $avgResponseTime = null; // Can be calculated if needed

        return response()->json([
            'total_conversations' => $totalConversations,
            'unread_conversations' => $unreadConversations,
            'today_messages' => $todayMessages,
            'avg_response_time' => $avgResponseTime,
        ]);
    }
}
