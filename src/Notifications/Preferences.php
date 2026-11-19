<?php

namespace Innertia\Notifications;

use Illuminate\Support\Facades\Schema;
use Innertia\Notifications\Models\NotificationPreference;
use Throwable;

/**
 * Per-user notice preferences (bell / mail). Critical types ignore preferences: they are
 * always delivered on their default channels and cannot be turned off.
 */
final class Preferences
{
    private static ?bool $table = null;

    public static function allows(string $userId, NoticeType $type, string $channel): bool
    {
        if ($type->critical || ! self::tableExists()) {
            return $type->defaultsTo($channel);
        }

        $row = NotificationPreference::query()
            ->where('user_id', $userId)->where('type', $type->key)->where('channel', $channel)
            ->first();

        return $row ? (bool) $row->enabled : $type->defaultsTo($channel);
    }

    /**
     * Every registered type with the user's effective state per channel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(string $userId): array
    {
        $rows = self::tableExists()
            ? NotificationPreference::query()->where('user_id', $userId)->get()
                ->mapWithKeys(fn ($p) => [$p->type . '|' . $p->channel => (bool) $p->enabled])->all()
            : [];

        $result = [];

        foreach (NoticeTypes::all() as $type) {
            $channels = [];
            foreach (NoticeType::CHANNELS as $channel) {
                $default = $type->defaultsTo($channel);
                $channels[$channel] = [
                    'available' => $default || ! $type->critical,
                    'enabled'   => $type->critical ? $default : ($rows[$type->key . '|' . $channel] ?? $default),
                    'locked'    => $type->critical,
                ];
            }

            $result[] = $type->toArray() + ['preferences' => $channels];
        }

        return $result;
    }

    /**
     * Saves preferences. Unknown types/channels and critical types are ignored (the server
     * never lets a critical notice be turned off).
     *
     * @param array<int, array{type: string, channel: string, enabled: bool}> $items
     * @return int rows written
     */
    public static function save(string $userId, array $items): int
    {
        $written = 0;

        foreach ($items as $item) {
            $type = NoticeTypes::get((string) ($item['type'] ?? ''));
            $channel = (string) ($item['channel'] ?? '');

            if (! $type || $type->critical || ! in_array($channel, NoticeType::CHANNELS, true)) {
                continue;
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'type' => $type->key, 'channel' => $channel],
                ['enabled' => (bool) ($item['enabled'] ?? false)],
            );
            $written++;
        }

        return $written;
    }

    public static function flush(): void
    {
        self::$table = null;
    }

    private static function tableExists(): bool
    {
        if (self::$table === true) {
            return true;
        }

        try {
            $exists = Schema::hasTable('notification_preferences');
        } catch (Throwable) {
            return false;
        }

        if ($exists) {
            self::$table = true;
        }

        return $exists;
    }
}
