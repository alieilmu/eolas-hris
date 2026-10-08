<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The bell in the admin sidebar: pengajuan from the employee portal (update data,
 * deklarasi, dinas, cuti, Cuti Besar) waiting for this account.
 */
class NotifikasiController extends Controller
{
    /** The latest 30, unread first. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = $user->notifications()
            ->orderByRaw('read_at is null desc')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (DatabaseNotification $n) => self::item($n));

        return response()->json(['items' => $items, 'unread' => $user->unreadNotifications()->count()]);
    }

    /** Asked every minute by the page: how many are unread, and the newest one (for the pop-up). */
    public function count(Request $request): JsonResponse
    {
        $unread = $request->user()->unreadNotifications();
        $latest = (clone $unread)->latest()->first();

        return response()->json(['unread' => $unread->count(), 'terbaru' => $latest ? self::item($latest) : null]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->update(['read_at' => now()]);

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    private static function item(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'jenis' => $n->data['jenis'] ?? null,
            'judul' => $n->data['judul'] ?? '',
            'isi' => $n->data['isi'] ?? '',
            'url' => $n->data['url'] ?? null,
            'dibaca' => $n->read_at !== null,
            'waktu' => $n->created_at?->toIso8601String(),
        ];
    }
}
