<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Support\Push\PushCategories;
use App\Support\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Akun → Notifikasi: this phone / browser receiving the account's notifications (Web Push),
 * which kinds go to the phone, the phones registered, and a test message.
 */
class PushController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $enabled = WebPush::enabled();

        return Inertia::render('Account/Notifications', [
            'enabled' => $enabled,
            'public_key' => $enabled ? rescue(fn () => WebPush::publicKey(), null) : null,
            'preferences' => PushCategories::preferences($user),
            'categories' => collect(PushCategories::LABELS)->map(fn ($c, $key) => ['key' => $key, ...$c])->values(),
            'devices' => $user->pushSubscriptions()->latest('id')->get()->map(fn (PushSubscription $s) => [
                'id' => $s->id, 'name' => $s->name, 'endpoint_hash' => $s->endpoint_hash, 'failures' => $s->failures,
                'created_at' => $s->created_at?->toIso8601String(), 'last_used_at' => $s->last_used_at?->toIso8601String(),
            ]),
        ]);
    }

    /** This phone allowed notifications: its push address and keys (again on every opening, kept fresh). */
    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(WebPush::enabled(), 404);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        if (! WebPush::allowedEndpoint($data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => 'Layanan push HP ini tidak dikenal.']);
        }
        rescue(fn () => WebPush::encrypt('{}', $data['keys']['p256dh'], $data['keys']['auth']), fn () => throw ValidationException::withMessages(['keys' => 'Kunci langganan push tidak valid.']), report: false);

        // One phone, one account: signing in with another account on it moves it there.
        $s = PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'name' => $data['name'] ?? null, 'failures' => 0,
        ]);

        return response()->json(['id' => $s->id, 'endpoint_hash' => $s->endpoint_hash]);
    }

    /** Turned off on this phone (or signing out of it). */
    public function unsubscribe(Request $request): JsonResponse
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string', 'max:1000']])['endpoint'];
        $request->user()->pushSubscriptions()->where('endpoint_hash', hash('sha256', $endpoint))->delete();

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $device): RedirectResponse
    {
        $request->user()->pushSubscriptions()->whereKey($device)->delete();
        Inertia::flash('success', 'Perangkat dihapus dari notifikasi.');

        return back();
    }

    public function preferences(Request $request): RedirectResponse
    {
        $rules = collect(PushCategories::LABELS)->mapWithKeys(fn ($_, $key) => ["preferences.{$key}" => ['required', 'boolean']])->all();
        $data = $request->validate($rules);
        $request->user()->forceFill(['push_preferences' => collect($data['preferences'])->map(fn ($v) => (bool) $v)->all()])->save();
        Inertia::flash('success', 'Pilihan notifikasi disimpan.');

        return back();
    }

    /** A message to every phone of the account now (not queued), with what each push service said. */
    public function test(Request $request): JsonResponse
    {
        abort_unless(WebPush::enabled(), 404);
        $results = $request->user()->pushSubscriptions()->get()->map(fn (PushSubscription $s) => [
            'id' => $s->id,
            'status' => rescue(fn () => WebPush::send($s, ['title' => 'Tes notifikasi', 'body' => 'Notifikasi di HP ini sudah aktif.', 'url' => '/account/notifications', 'tag' => 'push-test'], 300, 'high'), 0),
        ]);

        return response()->json([
            'sent' => $results->filter(fn ($r) => $r['status'] >= 200 && $r['status'] < 300)->count(),
            'total' => $results->count(),
            'results' => $results->values(),
        ]);
    }
}
