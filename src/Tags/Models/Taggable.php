<?php

namespace Innertia\Tags\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Taggable extends Pivot
{
    protected $table = 'taggables';

    public $incrementing = false;
    public $timestamps   = false;

    protected $fillable = [
        'tag_id',
        'taggable_type',
        'taggable_id',
        'tagged_by',
        'tagged_at',
    ];

    protected $casts = [
        'tagged_at' => 'datetime',
    ];
}
