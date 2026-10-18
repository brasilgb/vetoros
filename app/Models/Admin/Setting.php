<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $guarded = ['_method'];

    protected function casts(): array
    {
        return ['web_chat_enabled' => 'boolean'];
    }
}
