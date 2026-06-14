<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalApiKey extends Model
{
    protected $fillable = [
        'user_id',
        'app_id',
        'secret',
        'for',
        'is_active'
    ];
}
