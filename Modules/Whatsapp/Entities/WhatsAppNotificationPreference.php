<?php

namespace Modules\Whatsapp\Entities;

use Illuminate\Database\Eloquent\Model;

class WhatsAppNotificationPreference extends Model
{
    protected $table = 'whatsapp_notification_preferences';

    protected $guarded = ['id'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    /**
     * Get restaurant.
     */
    public function restaurant()
    {
        return $this->belongsTo(\App\Models\Restaurant::class);
    }
}

