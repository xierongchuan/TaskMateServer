<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;
    use Auditable;

    protected $table = 'shifts';

    protected $fillable = [
        'user_id',
        'dealership_id',
        'shift_schedule_id',
        'shift_start',
        'shift_end',
        'opening_photo_path',
        'closing_photo_path',
        'status',
        'late_minutes',
        'scheduled_start',
        'scheduled_end',
        'archived_tasks_processed',
    ];

    protected $casts = [
        'shift_start' => 'datetime',
        'shift_end' => 'datetime',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'late_minutes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'archived_tasks_processed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dealership()
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    public function schedule()
    {
        return $this->belongsTo(ShiftSchedule::class, 'shift_schedule_id')->withTrashed();
    }

}
