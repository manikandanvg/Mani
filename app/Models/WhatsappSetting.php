<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSetting extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * The single settings row, seeded from config('services.whatsapp') defaults the
     * first time. Use ->effective() to read values with a config fallback per field.
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'url' => config('services.whatsapp.url'),
            'instance_id' => config('services.whatsapp.instance_id'),
            'access_token' => config('services.whatsapp.access_token'),
            'country_code' => config('services.whatsapp.country_code', '91'),
            'is_active' => true,
        ]);
    }

    /** Effective gateway config: DB value per field, falling back to config/env. */
    public function effective(): array
    {
        return [
            'url' => $this->url ?: config('services.whatsapp.url'),
            'instance_id' => $this->instance_id ?: config('services.whatsapp.instance_id'),
            'access_token' => $this->access_token ?: config('services.whatsapp.access_token'),
            'country_code' => $this->country_code ?: config('services.whatsapp.country_code', '91'),
            'is_active' => $this->is_active,
        ];
    }
}
