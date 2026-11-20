<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** Notifications de l'utilisateur connecté (cloche de la barre du haut). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->limit(30)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id, 'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String(),
            ] + $n->data),
            'meta' => ['unread' => $user->unreadNotifications()->count()],
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['meta' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['meta' => ['unread' => 0]]);
    }
}
