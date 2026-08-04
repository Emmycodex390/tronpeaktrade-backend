<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    private const AUTO_REPLY = "Thanks for reaching out! A member of our team will be with you shortly. In the meantime, feel free to describe what you need help with.";

    // Support agent display names shown to users instead of a generic
    // "admin" label — picked deterministically per user_id so it's
    // consistent across the whole conversation, but varies between users.
    private const AGENT_NAMES = ['Benjamin', 'Sofia', 'Kenji', 'Amara', 'Lukas', 'Priya'];

    private function agentNameFor(int $userId): string
    {
        return self::AGENT_NAMES[$userId % count(self::AGENT_NAMES)];
    }

    /**
     * GET /api/chat/{chatId}/messages
     */
    public function fetchMessages(Request $request, $chatId)
    {
        $user = $request->user();

        if ($user->id != $chatId && $user->role !== 'admin') {
            return response()->json(['error' => 'Not authorized to view this conversation'], 403);
        }

        $conversation = ChatConversation::where('user_id', $chatId)->first();

        $response = [
            'success' => true,
            'status' => $conversation->status ?? 'open',
            'agent_name' => $this->agentNameFor((int) $chatId),
            'data' => Chat::where('user_id', $chatId)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'sender' => $m->sender,
                    'message' => $m->message,
                    'image_url' => $m->image_path ? asset('storage/' . $m->image_path) : null,
                    'created_at' => $m->created_at,
                ]),
        ];

        if ($user->role === 'admin') {
            $chatUser = User::find($chatId);
            $response['user'] = $chatUser ? [
                'id' => $chatUser->id,
                'name' => $chatUser->name,
                'email' => $chatUser->email,
                'last_seen_at' => $chatUser->last_seen_at,
            ] : null;
        }

        return response()->json($response);
    }

    /**
     * POST /api/chat/send
     *
     * Accepts a text message, an image, or both. multipart/form-data
     * when an image is attached (that's what the image validation rule
     * requires), plain JSON otherwise.
     */
    public function sendMessage(Request $request)
    {
        $data = $request->validate([
            'message' => 'nullable|string',
            'chat_id' => 'nullable|exists:users,id',
            'image' => 'nullable|image|max:5120', // 5MB
        ]);

        if (empty($data['message']) && !$request->hasFile('image')) {
            return response()->json(['error' => 'Message cannot be empty'], 422);
        }

        $user = $request->user();
        $isAdmin = $user->role === 'admin';
        $chatId = ($isAdmin && !empty($data['chat_id'])) ? $data['chat_id'] : $user->id;

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('chat-images', 'public');
        }

        $isFirstMessage = !$isAdmin && Chat::where('user_id', $chatId)->doesntExist();

        $message = Chat::create([
            'user_id' => $chatId,
            'sender' => $isAdmin ? 'admin' : 'user',
            'message' => $data['message'] ?? '',
            'image_path' => $imagePath,
        ]);

        // Keep the conversation record in sync — reopens it if a user
        // messages back into a conversation that was marked resolved.
        ChatConversation::updateOrCreate(
            ['user_id' => $chatId],
            ['status' => 'open']
        );

        if ($isFirstMessage) {
            Chat::create([
                'user_id' => $chatId,
                'sender' => 'admin',
                'message' => self::AUTO_REPLY,
            ]);
        }

        if (!$isAdmin) {
            PushService::notifyAdmins(
                "New message from {$user->name}",
                $data['message'] ?? 'Sent an image',
                "/admin/chats/{$chatId}",
                ['type' => 'chat', 'chatId' => (int) $chatId]
            );
        }

        return response()->json(['success' => true, 'data' => $message]);
    }

    /**
     * POST /api/chat/chat/{chatId}/read
     */
    public function markAsRead(Request $request, $chatId)
    {
        $user = $request->user();

        if ($user->id != $chatId && $user->role !== 'admin') {
            return response()->json(['error' => 'Not authorized'], 403);
        }

        $senderToMark = $user->role === 'admin' ? 'user' : 'admin';

        Chat::where('user_id', $chatId)
            ->where('sender', $senderToMark)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/admin/chats — inbox of every conversation, most recently
     * active first, with a preview of the last message and unread count.
     */
    public function listConversations(Request $request)
    {
        $conversations = ChatConversation::with('user:id,name,email,last_seen_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function ($c) {
                $last = Chat::where('user_id', $c->user_id)->orderByDesc('created_at')->first();
                $unread = Chat::where('user_id', $c->user_id)
                    ->where('sender', 'user')
                    ->whereNull('read_at')
                    ->count();

                return [
                    'user_id' => $c->user_id,
                    'user' => $c->user,
                    'status' => $c->status,
                    'last_message' => $last?->message ?: ($last?->image_path ? 'Sent an image' : null),
                    'last_sender' => $last?->sender,
                    'last_at' => $last?->created_at,
                    'unread_count' => $unread,
                ];
            });

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    /**
     * POST /api/admin/chats/{userId}/resolve
     */
    public function resolveConversation($userId)
    {
        ChatConversation::updateOrCreate(['user_id' => $userId], ['status' => 'resolved']);
        return response()->json(['success' => true]);
    }
}
