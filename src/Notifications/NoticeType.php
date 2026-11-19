<?php

namespace Innertia\Notifications;

/**
 * Definition of a notice type (registered by the product in NoticeTypes).
 *
 *   new NoticeType(
 *       key: 'call.missed', label: 'Llamada perdida',
 *       description: 'Cuando una llamada a tu anexo no fue contestada.',
 *       channels: [NoticeType::BELL],              // default channels
 *       debounceMinutes: 0,                         // skip repeats of (type, subject, user) within N min
 *       group: true, groupWindowMinutes: 60,        // fold repeats into one bell item with a counter
 *       groupTitle: ':count llamadas perdidas',
 *   );
 *
 * critical: always delivered on its default channels; preferences cannot turn it off.
 */
final class NoticeType
{
    public const BELL = 'bell';
    public const MAIL = 'mail';
    public const CHANNELS = [self::BELL, self::MAIL];

    /**
     * @param string[] $channels default channels
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description = '',
        public readonly array $channels = [self::BELL, self::MAIL],
        public readonly bool $critical = false,
        public readonly int $debounceMinutes = 0,
        public readonly bool $group = false,
        public readonly int $groupWindowMinutes = 60,
        public readonly ?string $groupTitle = null,
        public readonly ?string $category = null,
    ) {}

    /** Type used for keys nobody registered: bell + mail, no debounce. */
    public static function adhoc(string $key): self
    {
        return new self(key: $key, label: $key);
    }

    public function defaultsTo(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /** Bell title for a grouped notice ("3 llamadas perdidas"). */
    public function titleForCount(int $count, string $fallback): string
    {
        if ($count <= 1 || $this->groupTitle === null) {
            return $count > 1 ? "{$fallback} ({$count})" : $fallback;
        }

        return str_replace(':count', (string) $count, $this->groupTitle);
    }

    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'description' => $this->description,
            'channels'    => array_values($this->channels),
            'critical'    => $this->critical,
            'category'    => $this->category,
        ];
    }
}
