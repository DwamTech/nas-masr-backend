<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\User;
use App\Models\UserConversation;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ChatService
{
    /*
    |--------------------------------------------------------------------------
    | Conversation ID Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Generate or retrieve a consistent conversation ID between two parties.
     * The ID is deterministic: same parties always get the same conversation_id.
     *
     * @param mixed $partyA First party (User model or ID with type)
     * @param mixed $partyB Second party (User model or ID with type)
     * @param string $type Conversation type (peer, support, broadcast)
     * @return string UUID conversation_id
     */
    public function getConversationId($partyA, $partyB, string $type = UserConversation::TYPE_PEER): string
    {
        // For support type, use a fixed receiver identifier
        if ($type === UserConversation::TYPE_SUPPORT) {
            $partyAId = $this->getPartyIdentifier($partyA);
            return $this->generateDeterministicUuid("support:{$partyAId}");
        }

        // For broadcast, each broadcast gets a unique ID
        if ($type === UserConversation::TYPE_BROADCAST) {
            return (string) Str::uuid();
        }

        // For peer conversations, create deterministic ID from both parties
        $partyAId = $this->getPartyIdentifier($partyA);
        $partyBId = $this->getPartyIdentifier($partyB);

        // Sort to ensure same ID regardless of who initiates
        $sorted = collect([$partyAId, $partyBId])->sort()->values();

        return $this->generateDeterministicUuid("peer:{$sorted[0]}:{$sorted[1]}");
    }

    /**
     * Get a unique identifier string for a party.
     */
    private function getPartyIdentifier($party): string
    {
        if ($party instanceof User) {
            return User::class . ':' . $party->id;
        }

        if (is_array($party) && isset($party['id'], $party['type'])) {
            return $party['type'] . ':' . $party['id'];
        }

        return (string) $party;
    }

    /**
     * Generate a deterministic UUID from a string.
     */
    private function generateDeterministicUuid(string $input): string
    {
        $hash = md5($input);
        
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Send Message
    |--------------------------------------------------------------------------
    */

    /**
     * Send a message from sender to receiver.
     *
     * @param User $sender The user sending the message
     * @param User|null $receiver The user receiving the message (null for broadcast)
     * @param string $message The message content
     * @param string $type The conversation type
     * @return UserConversation The created message
     */
    public function sendMessage(
        User $sender,
        ?User $receiver,
        string $message,
        string $type = UserConversation::TYPE_PEER,
        ?int $listingId = null,
        string $contentType = UserConversation::CONTENT_TYPE_TEXT,
        ?string $attachment = null
    ): UserConversation {
        $conversationId = $this->getConversationId($sender, $receiver, $type);

        $conversation = UserConversation::create([
            'conversation_id' => $conversationId,
            'sender_id' => $sender->id,
            'sender_type' => User::class,
            'receiver_id' => $receiver?->id,
            'receiver_type' => $receiver ? User::class : null,
            'message' => $message,
            'attachment' => $attachment,
            'type' => $type,
            'listing_id' => $listingId,
            'content_type' => $contentType,
        ]);

        // Dispatch real-time event
        event(new MessageSent($conversation));

        return $conversation;
    }

    /**
     * Send a support message (user to admin team).
     */
    public function sendSupportMessage(User $sender, string $message): UserConversation
    {
        return $this->sendMessage($sender, null, $message, UserConversation::TYPE_SUPPORT);
    }

    /**
     * Admin reply to support message.
     */
    public function adminReplyToSupport(User $admin, User $user, string $message): UserConversation
    {
        $conversationId = $this->getConversationId($user, null, UserConversation::TYPE_SUPPORT);

        $conversation = UserConversation::create([
            'conversation_id' => $conversationId,
            'sender_id' => $admin->id,
            'sender_type' => User::class,
            'receiver_id' => $user->id,
            'receiver_type' => User::class,
            'message' => $message,
            'type' => UserConversation::TYPE_SUPPORT,
        ]);

        event(new MessageSent($conversation));

        return $conversation;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Conversation History
    |--------------------------------------------------------------------------
    */

    /**
     * Get message history for a conversation.
     *
     * @param string $conversationId The conversation UUID
     * @param int $perPage Number of messages per page
     * @return LengthAwarePaginator Paginated messages
     */
    public function getHistory(string $conversationId, int $perPage = 50): LengthAwarePaginator
    {
        return UserConversation::inConversation($conversationId)
            ->with(['sender', 'receiver', 'listing'])
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);
    }

    /**
     * Get conversation history between two users.
     */
    public function getHistoryBetweenUsers(User $userA, User $userB, int $perPage = 50): LengthAwarePaginator
    {
        $conversationId = $this->getConversationId($userA, $userB);
        return $this->getHistory($conversationId, $perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | Get Inbox (Conversation List)
    |--------------------------------------------------------------------------
    */

    /**
     * Get user's inbox - list of conversations with last message.
     *
     * @param User $user The user to get inbox for
     * @param string|null $type Filter by conversation type
     * @return Collection List of conversations with last message
     */
    public function getInbox(User $user, ?string $type = null): Collection
    {
        $query = UserConversation::forUser($user->id, User::class)
            ->with(['sender', 'receiver']);

        if ($type) {
            $query->ofType($type);
        }

        // Get latest message per conversation
        $latestMessages = $query
            ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY conversation_id ORDER BY created_at DESC) as rn')
            ->get()
            ->filter(fn($msg) => $msg->rn === 1)
            ->values();

        return $latestMessages->map(function ($message) use ($user) {
            // Determine the other party in the conversation
            $otherParty = null;
            $isSentByMe = $message->sender_id === $user->id && $message->sender_type === User::class;
            
            if ($isSentByMe) {
                $otherParty = $message->receiver;
            } else {
                $otherParty = $message->sender;
            }

            // is_read logic:
            // - If I sent the last message, consider it "read" (I already saw it)
            // - If I received the last message, check if read_at is set
            $isRead = $isSentByMe ? true : $message->isRead();

            return [
                'conversation_id' => $message->conversation_id,
                'type' => $message->type,
                'content_type' => $message->content_type,
                'last_message' => $message->message,
                'attachment' => $message->attachment ? asset('storage/' . $message->attachment) : null,
                'last_message_at' => $message->created_at,
                'is_read' => $isRead,
                'other_party' => $otherParty ? [
                    'id' => $otherParty->id,
                    'name' => $otherParty->name,
                ] : null,
                'unread_count' => $this->getUnreadCount($message->conversation_id, $user),
            ];
        });
    }

    /**
     * Get support inbox for admins (all support conversations).
     */
    public function getSupportInbox(int $perPage = 20): LengthAwarePaginator
    {
        return UserConversation::support()
            ->with(['sender', 'receiver'])
            ->selectRaw('conversation_id, MAX(created_at) as last_message_at')
            ->groupBy('conversation_id')
            ->orderByDesc('last_message_at')
            ->paginate($perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
    */

    /**
     * Get unread message count for a conversation.
     */
    public function getUnreadCount(string $conversationId, User $user): int
    {
        return UserConversation::inConversation($conversationId)
            ->where('receiver_id', $user->id)
            ->where('receiver_type', User::class)
            ->unread()
            ->count();
    }

    /**
     * Mark all messages in a conversation as read for a user.
     */
    public function markConversationAsRead(string $conversationId, User $user): int
    {
        return UserConversation::inConversation($conversationId)
            ->where('receiver_id', $user->id)
            ->where('receiver_type', User::class)
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Check if a conversation exists between two users.
     */
    public function conversationExists(User $userA, User $userB): bool
    {
        $conversationId = $this->getConversationId($userA, $userB);
        return UserConversation::inConversation($conversationId)->exists();
    }

    /**
     * Get total unread messages count for a user.
     */
    public function getTotalUnreadCount(User $user): int
    {
        return UserConversation::where('receiver_id', $user->id)
            ->where('receiver_type', User::class)
            ->unread()
            ->count();
    }
}
