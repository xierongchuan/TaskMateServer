<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class AutoDealership extends Model
{
    use HasFactory;
    use Auditable;

    protected $table = 'auto_dealerships';

    protected $fillable = [
        'name',
        'address',
        'phone',
        'timezone',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'dealership_user', 'dealership_id', 'user_id')
            ->withTimestamps();
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'dealership_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'dealership_id');
    }

    public function importantLinks()
    {
        return $this->hasMany(ImportantLink::class, 'dealership_id');
    }

    public function shiftSchedules()
    {
        return $this->hasMany(ShiftSchedule::class, 'dealership_id');
    }
}
