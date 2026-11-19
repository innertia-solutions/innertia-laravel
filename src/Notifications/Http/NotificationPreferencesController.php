<?php

namespace Innertia\Notifications\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Notifications\NoticeType;
use Innertia\Notifications\Preferences;
use Innertia\Notifications\UseCases\SaveNotificationPreferences;

/**
 *   GET me/notification-preferences  → {data: [{key,label,description,critical,category,channels,preferences:{bell:{enabled,locked,available},mail:{…}}}]}
 *   PUT me/notification-preferences  {preferences: [{type, channel, enabled}]}
 */
class NotificationPreferencesController extends Controller
{
    public function index(Request $request): array
    {
        return ['data' => Preferences::forUser((string) $request->user()->getAuthIdentifier())];
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'preferences'           => 'required|array|max:500',
            'preferences.*.type'    => 'required|string|max:120',
            'preferences.*.channel' => 'required|string|in:' . implode(',', NoticeType::CHANNELS),
            'preferences.*.enabled' => 'required|boolean',
        ]);

        return ['data' => (new SaveNotificationPreferences((string) $request->user()->getAuthIdentifier(), $data['preferences']))->execute()];
    }
}
