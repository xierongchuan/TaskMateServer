<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Enums\Role;
use App\Traits\Auditable;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use SoftDeletes;
    use Auditable;

    protected $table = 'users';

    protected $fillable = [
        'login',
        'full_name',
        'phone',
        'role',
        'dealership_id',
        'password',
        'failed_login_attempts',
        'locked_until',
        'last_failed_login_at',
    ];

    protected $casts = [
        'role' => Role::class,
        'locked_until' => 'datetime',
        'last_failed_login_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    public function dealership()
    {
        // For backward compatibility, if dealership_id is set, return it.
        // Otherwise, return the first dealership from the pivot table.
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }

    public function dealerships()
    {
        return $this->belongsToMany(AutoDealership::class, 'dealership_user', 'user_id', 'dealership_id')
            ->withTimestamps();
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'user_id');
    }

    public function taskAssignments()
    {
        return $this->hasMany(TaskAssignment::class, 'user_id');
    }

    public function taskResponses()
    {
        return $this->hasMany(TaskResponse::class, 'user_id');
    }

    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'creator_id');
    }

    public function createdLinks()
    {
        return $this->hasMany(ImportantLink::class, 'creator_id');
    }

    /**
     * Get IDs of all dealerships accessible to this user.
     * Includes primary dealership_id and attached dealerships.
     *
     * @return array<int>
     */
    public function getAccessibleDealershipIds(): array
    {
        $ids = [];

        if ($this->dealership_id) {
            $ids[] = $this->dealership_id;
        }

        $attachedIds = $this->dealerships()->pluck('auto_dealerships.id')->toArray();
        $ids = array_merge($ids, $attachedIds);

        return array_unique($ids);
    }
}
