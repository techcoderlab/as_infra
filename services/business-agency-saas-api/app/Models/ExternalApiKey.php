<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalApiKey extends Model
{
    protected $fillable = [
        'app_id',
        'secret',
        'for',
        'is_active',
    ];
}
