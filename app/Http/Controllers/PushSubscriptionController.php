<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): Response
    {
        abort_unless(filled(config('webpush.public_key')) && filled(config('webpush.private_key')), 503, 'Push notifications are not configured.');

        $data = $request->validate([
            'endpoint' => ['bail', 'required', 'string', 'max:2048', 'url:https', function (string $attribute, mixed $value, \Closure $fail): void {
                $host = strtolower(parse_url($value, PHP_URL_HOST) ?: '');
                $port = parse_url($value, PHP_URL_PORT);
                $allowed = in_array($host, ['web.push.apple.com', 'fcm.googleapis.com', 'updates.push.services.mozilla.com'], true)
                    || str_ends_with($host, '.push.apple.com');

                if (! $allowed || ($port !== null && $port !== 443) || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
                    $fail(__('Недопустимый адрес службы уведомлений.'));
                }
            }],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{87}=?$/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}(==)?$/'],
        ]);

        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'session_hash' => hash('sha256', $request->session()->getId()),
        ]);

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->noContent();
    }
}
