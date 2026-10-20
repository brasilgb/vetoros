<?php

namespace App\Models\App;

use App\Support\OrderFiles;
use App\Tenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    use HasFactory, Tenantable;

    /** URL assinada e temporária da foto (o arquivo é privado; ver App\Support\OrderFiles). */
    protected $appends = ['url'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->getKey() ? OrderFiles::imageUrl($this) : null;
    }
}
