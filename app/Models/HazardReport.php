<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\HazardAttachment;
use App\Models\HazardStatusHistory;

class HazardReport extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reporter_user_id',
        'category_id',
        'location_id',
        'severity',
        'observed_at',
        'description',
        'current_status_id',
        'assigned_to_user_id',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function category()
    {
        return $this->belongsTo(HazardCategory::class, 'category_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function currentStatus()
    {
        return $this->belongsTo(HazardStatus::class, 'current_status_id');
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(HazardStatusHistory::class, 'hazard_report_id')->orderBy('created_at');
    }

    public function attachments()
    {
        return $this->hasMany(HazardAttachment::class, 'hazard_report_id')->orderBy('created_at');
    }
}

