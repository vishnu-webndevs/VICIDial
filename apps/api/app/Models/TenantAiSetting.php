<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class TenantAiSetting extends Model
{
    use HasFactory;

    protected $table = 'tenant_ai_settings';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'provider',
        'api_key',
        'enabled',
        'default_model',
        'google_calendar_service_account_json',
        'google_calendar_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function setApiKeyAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['api_key'] = null;
        } else {
            $this->attributes['api_key'] = Crypt::encryptString($value);
        }
    }

    public function getApiKeyAttribute($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public function setGoogleCalendarServiceAccountJsonAttribute($value): void
    {
        if (empty($value)) {
            $this->attributes['google_calendar_service_account_json'] = null;
        } else {
            $this->attributes['google_calendar_service_account_json'] = Crypt::encryptString(is_array($value) ? json_encode($value) : $value);
        }
    }

    public function getGoogleCalendarServiceAccountJsonAttribute($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
