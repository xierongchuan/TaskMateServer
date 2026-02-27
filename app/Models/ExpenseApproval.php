<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseApproval extends Model
{
    protected $table = 'expense_approvals';

    protected $fillable = [
        'expense_request_id',
        'actor_id',
        'actor_role',
        'action',
        'comment',
        'created_at',
    ];

    public $timestamps = false; // created_at пишем вручную

    protected $casts = [
    'created_at' => 'datetime',
    ];
}
