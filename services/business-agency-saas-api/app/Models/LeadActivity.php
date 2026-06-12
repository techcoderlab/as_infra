<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    protected $fillable = ['lead_id', 'type', 'content', 'metadata'];

    protected $casts = [
        'metadata' => 'array', // Ensures Vue receives JSON, not a string
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
