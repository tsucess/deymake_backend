<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'key',
        'method',
        'path',
        'request_hash',
        'status_code',
        'response_body',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
