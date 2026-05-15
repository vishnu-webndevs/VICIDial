<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetaWhatsappTemplate extends Model
{
    use HasFactory;

    protected $table = 'meta_whatsapp_templates';

    protected $fillable = [
        'tenant_id',
        'provider_account_id',
        'meta_template_id',
        'template_name',
        'category',
        'language',
        'status',
        'components',
        'has_header',
        'has_body',
        'has_footer',
        'has_buttons',
        'button_count',
        'variable_count',
        'raw_payload',
        'synced_at',
        'last_updated_at',
    ];

    protected $casts = [
        'components' => AsArrayObject::class,
        'raw_payload' => AsArrayObject::class,
        'has_header' => 'boolean',
        'has_body' => 'boolean',
        'has_footer' => 'boolean',
        'has_buttons' => 'boolean',
        'synced_at' => 'datetime',
        'last_updated_at' => 'datetime',
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
