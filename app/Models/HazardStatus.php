<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HazardStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'sort_order',
        'is_terminal',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_terminal' => 'boolean',
    ];
}

