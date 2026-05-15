<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetaTemplateSyncLog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'meta_template_sync_logs';

    protected $fillable = [
        'tenant_id',
        'provider_account_id',
        'sync_started_at',
        'sync_completed_at',
        'templates_fetched',
        'templates_synced',
        'templates_updated',
        'templates_failed',
        'error_message',
        'status',
        'raw_response',
    ];

    protected $casts = [
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
        'raw_response' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function providerAccount()
    {
        return $this->belongsTo(ProviderAccount::class);
    }
}
