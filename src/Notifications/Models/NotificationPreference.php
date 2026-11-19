<?php

namespace Innertia\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** user × notice type × channel → enabled. Absent row = the type's default channels. */
class NotificationPreference extends Model
{
    use HasUuids;

    protected $table = 'notification_preferences';

    protected $fillable = ['user_id', 'type', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
