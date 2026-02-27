<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseRequest extends Model
{
    use HasFactory;

    protected $table = 'expense_requests';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'requester_id',
        'title',
        'description',
        'amount',
        'currency',
        'status',
        'director_id',
        'accountant_id',
        'director_comment',
        'company_id',
        'approved_at',
        'issued_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function director()
    {
        return $this->belongsTo(User::class, 'director_id');
    }

    public function accountant()
    {
        return $this->belongsTo(User::class, 'accountant_id');
    }
}
