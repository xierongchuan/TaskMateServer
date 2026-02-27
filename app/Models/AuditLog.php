<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;
    protected $table = 'audit_logs';

    protected $fillable = [
        'table_name',
        'record_id',
        'actor_id',
        'dealership_id',
        'action',
        'payload',
        'created_at',
    ];

    public $timestamps = false;

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Связь с пользователем, выполнившим действие.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Связь с автосалоном.
     */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(AutoDealership::class, 'dealership_id');
    }
}
