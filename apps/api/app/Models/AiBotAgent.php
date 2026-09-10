<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiBotAgent extends Model
{
    use HasFactory;

    protected $table = 'ai_bot_agents';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'name',
        'description',
        'system_instructions',
        'privacy_policy',
        'knowledge_base',
        'custom_knowledge_prompt',
        'fallback_message',
        'strict_mode',
        'human_delay_seconds',
        'is_active',
    ];

    protected $casts = [
        'knowledge_base' => 'array',
        'strict_mode' => 'boolean',
        'is_active' => 'boolean',
        'human_delay_seconds' => 'integer',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function interactiveFlows(): HasMany
    {
        return $this->hasMany(AiBotInteractiveFlow::class, 'ai_bot_agent_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'ai_bot_agent_id');
    }
}
