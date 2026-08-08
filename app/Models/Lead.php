<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Lead extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'message',
        'event_id',
        'event_name',
        'event_date',
        'event_time',
        'event_price',
        'event_location',
        'seats',
        'payment_status',
        'security_nonce',
        'stripe_session_id',
        'stripe_payment_intent',
        'telegram_sent',
    ];

    protected $casts = [
        'seats' => 'integer',
    ];

    /**
     * Multi-spot orders need a column the database may not have yet — the code
     * can go live a moment before its migration runs. Until it does, every
     * order is one spot: checkout keeps working and no seat is double-sold.
     */
    public static function tracksSeats(): bool
    {
        static $has = null;

        if ($has === null) {
            try {
                $has = Schema::hasColumn('leads', 'seats');
            } catch (\Throwable $e) {
                $has = false;
            }
        }

        return $has;
    }

    /** Spots this order books — 1 for anything booked before multi-spot orders existed. */
    public function seatCount(): int
    {
        return max(1, (int) ($this->seats ?? 1));
    }
}

