<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function plans()
    {
        return $this->belongsToMany(Plan::class, 'module_plan');
    }
}
