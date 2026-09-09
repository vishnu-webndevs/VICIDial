<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiBotInteractiveFlow extends Model
{
    use HasFactory;

    protected $table = 'ai_bot_interactive_flows';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'ai_bot_agent_id',
        'trigger_keyword',
        'response_type',
        'question_text',
        'options',
    ];

    protected $casts = [
        'options' => 'array',
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

    public function botAgent(): BelongsTo
    {
        return $this->belongsTo(AiBotAgent::class, 'ai_bot_agent_id');
    }
}
