<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'dealership_id',
        'key',
        'value',
        'type',
        'description',
    ];

    /**
     * Get the dealership that owns the setting.
     */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(AutoDealership::class);
    }

    /**
     * Get the typed value of the setting.
     *
     * @return mixed
     */
    public function getTypedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * Set the typed value of the setting.
     *
     * @param mixed $value
     */
    public function setTypedValue(mixed $value): void
    {
        // Handle null values for different types
        if ($value === null) {
            match ($this->type) {
                'integer' => $this->value = '0',
                'boolean' => $this->value = '0',
                'json' => $this->value = json_encode(null),
                'time' => $this->value = '00:00',
                default => $this->value = '',
            };
            return;
        }

        $this->value = match ($this->type) {
            'json' => json_encode($value),
            'boolean' => $value ? '1' : '0',
            'time' => $value, // Keep time format as-is
            default => (string) $value,
        };
    }
}
