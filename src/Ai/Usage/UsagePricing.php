<?php

namespace Innertia\Ai\Usage;

use Innertia\Ai\Models\AiModel;

/**
 * Cost of an AI usage in USD from the model's current prices.
 *
 * Units (all optional): input_tokens, output_tokens, audio_seconds, tts_chars.
 *   cost = input/1M × price_input_per_mtok + output/1M × price_output_per_mtok
 *        + audio_seconds/60 × price_per_audio_minute + tts_chars/1M × price_per_mchar_tts
 *
 * Returns null when the model is unknown or has no price at all (the event stays "unpriced";
 * reports count those separately instead of showing a misleading 0).
 */
final class UsagePricing
{
    public const UNIT_KEYS = ['input_tokens', 'output_tokens', 'audio_seconds', 'tts_chars'];

    public static function cost(?AiModel $model, array $units): ?float
    {
        if ($model === null || ! $model->isPriced()) {
            return null;
        }

        $cost = self::units($units, 'input_tokens') / 1_000_000 * (float) $model->price_input_per_mtok
            + self::units($units, 'output_tokens') / 1_000_000 * (float) $model->price_output_per_mtok
            + self::units($units, 'audio_seconds') / 60 * (float) $model->price_per_audio_minute
            + self::units($units, 'tts_chars') / 1_000_000 * (float) $model->price_per_mchar_tts;

        return round($cost, 8);
    }

    /**
     * Resolves the model row by key (preferring the given provider). Never throws: an
     * unreadable table (feature not installed) just means "no price".
     */
    public static function model(string|AiModel|null $model, ?string $providerId = null): ?AiModel
    {
        if ($model instanceof AiModel) {
            return $model;
        }

        if (blank($model)) {
            return null;
        }

        try {
            $candidates = AiModel::query()->where('key', $model)->get();
        } catch (\Throwable) {
            return null;
        }

        if ($candidates->count() > 1 && filled($providerId)) {
            return $candidates->firstWhere('provider_id', $providerId) ?? $candidates->first();
        }

        return $candidates->first();
    }

    private static function units(array $units, string $key): float
    {
        return max(0.0, (float) ($units[$key] ?? 0));
    }
}
