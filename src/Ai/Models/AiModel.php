<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AiModel — un modelo concreto ofrecido por un provider (p.ej. gpt-4o).
 */
class AiModel extends Model
{
    use HasUuids;

    protected $table = 'ai_models';

    protected $fillable = [
        'provider_id',
        'key',
        'name',
        'capabilities',
        'active',
        'price_input_per_mtok',
        'price_output_per_mtok',
        'price_per_audio_minute',
        'price_per_mchar_tts',
    ];

    /** Price columns (USD): per 1M input tokens, per 1M output tokens, per audio minute, per 1M TTS chars. */
    public const PRICE_FIELDS = ['price_input_per_mtok', 'price_output_per_mtok', 'price_per_audio_minute', 'price_per_mchar_tts'];

    protected $casts = [
        'capabilities'           => 'array',
        'active'                 => 'boolean',
        'price_input_per_mtok'   => 'float',
        'price_output_per_mtok'  => 'float',
        'price_per_audio_minute' => 'float',
        'price_per_mchar_tts'    => 'float',
    ];

    /** True when at least one price is set. */
    public function isPriced(): bool
    {
        foreach (self::PRICE_FIELDS as $field) {
            if ($this->getAttribute($field) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, float|null> */
    public function prices(): array
    {
        return $this->only(self::PRICE_FIELDS);
    }

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
