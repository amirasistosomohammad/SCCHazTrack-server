<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->with('hazardReport');

        $unreadCount = (clone $query)->whereNull('read_at')->count();

        $notifications = $query->limit(50)->get();

        return response()->json([
            'data' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(Request $request, int $id)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}

