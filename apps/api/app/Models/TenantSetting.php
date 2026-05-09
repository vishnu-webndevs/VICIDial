<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'timezone',
        'locale',
        'date_format',
        'branding_company_name',
        'branding_logo_url',
        'default_webhook_url',
        'alert_email',
        'default_caller_id',
        'voice_locale',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
