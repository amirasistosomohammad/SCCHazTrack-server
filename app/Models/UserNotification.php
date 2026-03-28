<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'hazard_report_id',
        'type',
        'title',
        'message',
        'status_key',
        'read_at',
        'created_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hazardReport()
    {
        return $this->belongsTo(HazardReport::class, 'hazard_report_id');
    }
}

