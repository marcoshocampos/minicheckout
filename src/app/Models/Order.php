<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUuids;

    protected $fillable = [
        'status',
        'amount_cents',
        'currency',
        'customer_email',
        'payment_id',
        'decline_reason',
        'paid_at',
        'notified_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
        'notified_at' => 'datetime',
    ];
}
