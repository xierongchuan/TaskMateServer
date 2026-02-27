<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskGeneratorAssignment extends Model
{
    use HasFactory;

    protected $table = 'task_generator_assignments';

    protected $fillable = [
        'generator_id',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function generator()
    {
        return $this->belongsTo(TaskGenerator::class, 'generator_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
