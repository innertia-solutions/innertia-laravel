<?php

namespace Innertia\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Outbound webhook subscription.
 *
 * Legacy rows (created before webhooks v2) keep a plaintext `secret` and receive the legacy body
 * (`payload + _event + _timestamp`). Rows created through CreateWebhook store an encrypted Standard Webhooks
 * secret (`whsec_…`) in `signing_secret` and receive the envelope `{type, timestamp, data}`.
 * Both always get the Standard Webhooks headers and `X-Innertia-Signature`.
 */
class Webhook extends Model
{
    protected $fillable = [
        'url',
        'description',
        'events',
        'secret',
        'active',
        'organization_id',
        'signing_secret',
        'previous_secret',
        'previous_secret_expires_at',
        'secret_hint',
        'scopes',
        'created_by',
        'disabled_at',
        'disabled_reason',
        'failing_since',
        'last_success_at',
        'last_failure_at',
    ];

    public function getFillable(): array
    {
        $fillable = parent::getFillable();

        if (\Innertia\Facades\Innertia::tenancyEnabled()) {
            array_unshift($fillable, 'tenant_id');
        }

        return $fillable;
    }

    protected $hidden = ['secret', 'signing_secret', 'previous_secret'];

    protected $casts = [
        'events'                     => 'array',
        'active'                     => 'boolean',
        'scopes'                     => 'array',
        'signing_secret'             => 'encrypted',
        'previous_secret'            => 'encrypted',
        'previous_secret_expires_at' => 'datetime',
        'disabled_at'                => 'datetime',
        'failing_since'              => 'datetime',
        'last_success_at'            => 'datetime',
        'last_failure_at'            => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    /**
     * Exact key, '*' or a family wildcard ('call.*' matches 'call.ended' and 'call.ai.summary').
     */
    public function matchesEvent(string $eventKey): bool
    {
        foreach ((array) $this->events as $pattern) {
            if (self::patternMatches((string) $pattern, $eventKey)) {
                return true;
            }
        }

        return false;
    }

    public static function patternMatches(string $pattern, string $eventKey): bool
    {
        if ($pattern === '*' || $pattern === $eventKey) {
            return true;
        }

        return str_ends_with($pattern, '.*') && str_starts_with($eventKey, substr($pattern, 0, -1));
    }

    /** Created before webhooks v2 (plaintext secret, legacy body). */
    public function isLegacy(): bool
    {
        return ! $this->hasV2Columns() || blank($this->signing_secret);
    }

    /** Current signing secret (the encrypted v2 one, or the legacy plaintext secret). */
    public function currentSecret(): string
    {
        return $this->isLegacy() ? (string) $this->secret : (string) $this->signing_secret;
    }

    /** Secrets to sign with: current first, then the previous one during its 24 h coexistence window. */
    public function signingSecrets(): array
    {
        $secrets = [$this->currentSecret()];

        if ($this->hasV2Columns() && filled($this->previous_secret)
            && $this->previous_secret_expires_at && $this->previous_secret_expires_at->isFuture()) {
            $secrets[] = (string) $this->previous_secret;
        }

        return array_values(array_filter($secrets, fn ($s) => $s !== ''));
    }

    /** Scopes granted to this subscription (sensitive data in payloads). */
    public function grantedScopes(): array
    {
        return array_values(array_filter((array) ($this->hasV2Columns() ? $this->scopes : []), 'is_string'));
    }

    /** True when the subscription is limited to a sede that is not the event's one. */
    public function excludesOrganization(?string $eventOrganizationId): bool
    {
        if (! $this->hasV2Columns() || blank($this->organization_id)) {
            return false;
        }

        return $eventOrganizationId === null || (string) $this->organization_id !== (string) $eventOrganizationId;
    }

    /** Serialized for the management API (never the secret). */
    public function toApiArray(): array
    {
        return [
            'id'                         => $this->id,
            'url'                        => $this->url,
            'description'                => $this->description,
            'events'                     => array_values((array) $this->events),
            'active'                     => (bool) $this->active,
            'organization_id'            => $this->organization_id !== null ? (string) $this->organization_id : null,
            'scopes'                     => $this->grantedScopes(),
            'secret_hint'                => $this->secret_hint,
            'previous_secret_expires_at' => $this->previous_secret_expires_at?->toIso8601String(),
            'disabled_at'                => $this->disabled_at?->toIso8601String(),
            'disabled_reason'            => $this->disabled_reason,
            'failing_since'              => $this->failing_since?->toIso8601String(),
            'last_success_at'            => $this->last_success_at?->toIso8601String(),
            'last_failure_at'            => $this->last_failure_at?->toIso8601String(),
            'legacy'                     => $this->isLegacy(),
            'created_by'                 => $this->created_by,
            'created_at'                 => $this->created_at?->toIso8601String(),
        ];
    }

    private function hasV2Columns(): bool
    {
        return array_key_exists('signing_secret', $this->attributes) || \Innertia\PublicApi\Support\IntegrationsSchema::webhooksV2();
    }
}
