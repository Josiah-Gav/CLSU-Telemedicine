<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleSlot extends Model
{
    use HasFactory;

    protected $table = 'schedule_slots';

    protected $primaryKey = 'slot_id';

    protected $fillable = [
        'physician_id',
        'slot_date',
        'start_time',
        'end_time',
        'status',
        'reminder_sent_at',
    ];

    protected $casts = [
        'slot_date' => 'date',
        'reminder_sent_at' => 'datetime',
    ];

    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physician_id', 'user_id');
    }
}
