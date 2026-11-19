<?php

namespace Innertia\Notifications;

/**
 * Registry of notice types. The lib ships the critical account types; each product registers
 * its own in a provider boot():
 *
 *   NoticeTypes::register(
 *       new NoticeType('voicemail.new', 'Mensaje de voz nuevo', channels: ['bell', 'mail']),
 *       new NoticeType('call.missed', 'Llamada perdida', channels: ['bell'], group: true,
 *                      groupTitle: ':count llamadas perdidas'),
 *   );
 */
final class NoticeTypes
{
    public const ACCOUNT_PASSWORD_CHANGED = 'account.password_changed';
    public const ACCOUNT_PASSKEY_ADDED = 'account.passkey_added';
    public const ACCOUNT_EMAIL_CHANGED = 'account.email_changed';
    public const ACCOUNT_INVITATION = 'account.invitation';
    public const WEBHOOK_DISABLED = 'webhook.disabled';

    /** @var array<string, NoticeType> */
    private static array $types = [];

    private static bool $defaults = false;

    public static function register(NoticeType ...$types): void
    {
        self::ensureDefaults();

        foreach ($types as $type) {
            self::$types[$type->key] = $type;
        }
    }

    public static function get(string $key): ?NoticeType
    {
        self::ensureDefaults();

        return self::$types[$key] ?? null;
    }

    /** Registered type or an ad-hoc one (bell + mail). */
    public static function resolve(string $key): NoticeType
    {
        return self::get($key) ?? NoticeType::adhoc($key);
    }

    /** @return array<string, NoticeType> */
    public static function all(): array
    {
        self::ensureDefaults();

        return self::$types;
    }

    /** Forgets product types (tests). The account types are re-registered. */
    public static function flush(): void
    {
        self::$types = [];
        self::$defaults = false;
    }

    private static function ensureDefaults(): void
    {
        if (self::$defaults) {
            return;
        }

        self::$defaults = true;

        foreach (self::accountTypes() as $type) {
            self::$types[$type->key] ??= $type;
        }
    }

    /** @return NoticeType[] */
    public static function accountTypes(): array
    {
        return [
            new NoticeType(self::ACCOUNT_PASSWORD_CHANGED, 'Contraseña cambiada', 'Aviso de seguridad cuando cambia tu contraseña.', [NoticeType::BELL, NoticeType::MAIL], critical: true, category: 'account'),
            new NoticeType(self::ACCOUNT_PASSKEY_ADDED, 'Passkey agregada', 'Aviso de seguridad cuando se agrega una passkey a tu cuenta.', [NoticeType::BELL, NoticeType::MAIL], critical: true, category: 'account'),
            new NoticeType(self::ACCOUNT_EMAIL_CHANGED, 'Correo cambiado', 'Aviso de seguridad cuando cambia el correo de tu cuenta.', [NoticeType::BELL, NoticeType::MAIL], critical: true, category: 'account'),
            new NoticeType(self::ACCOUNT_INVITATION, 'Invitación de cuenta', 'Link para activar tu cuenta.', [NoticeType::MAIL], critical: true, category: 'account'),
        ];
    }

    /**
     * @return NoticeType[] types emitted by the public API / webhooks engine. Not registered by default
     * (registered by \Innertia\PublicApi\Routes::manage(), i.e. only in products that adopt the engine).
     */
    public static function integrationTypes(): array
    {
        return [
            new NoticeType(self::WEBHOOK_DISABLED, 'Webhook desactivado', 'Cuando un webhook se desactiva tras 24 h de fallas continuas.', [NoticeType::BELL, NoticeType::MAIL], category: 'integrations'),
        ];
    }
}
