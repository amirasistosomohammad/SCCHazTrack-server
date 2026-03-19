<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HazardStatusHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'hazard_report_id',
        'from_status_id',
        'to_status_id',
        'changed_by_user_id',
        'note',
        'is_public',
        'created_at',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function report()
    {
        return $this->belongsTo(HazardReport::class, 'hazard_report_id');
    }

    public function fromStatus()
    {
        return $this->belongsTo(HazardStatus::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(HazardStatus::class, 'to_status_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}

