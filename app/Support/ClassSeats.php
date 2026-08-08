<?php

namespace App\Support;

use App\Models\Lead;
use Carbon\Carbon;

/**
 * How many spots of a class are taken, and how many are left.
 *
 * One place on purpose: the SOLD OUT badge, the spot picker in the sign-up
 * popup and the capacity check at checkout must never disagree, or the site
 * offers a spot the studio cannot seat.
 *
 * Two rules live here:
 *  - one order can book several spots (Lead::seats);
 *  - an order counts only for the class holding its id now. Class ids used to
 *    be recycled, and a new class would inherit the deleted one's sold spots
 *    (SUN-CROWNED showed SOLD OUT on PET PORTRAIT's twelve seats, 2026-08-08).
 */
class ClassSeats
{
    /** The one-off class with this id, as the site currently lists it. */
    public static function event(int $eventId): ?array
    {
        foreach (EventLinks::catalog()['events'] as $event) {
            if ((int) ($event['id'] ?? 0) === $eventId) {
                return $event;
            }
        }

        return null;
    }

    /** Per-class override, otherwise the studio-wide default. */
    public static function capacity(array $event): int
    {
        $capacity = (int) ($event['maxAttendees'] ?? 0);

        return $capacity > 0 ? $capacity : (int) config('services.event_max_attendees', 12);
    }

    /** Paid online, plus the cash / e-transfer bookings Alevtyna records in the CMS. */
    public static function taken(array $event): int
    {
        $offline = max(0, (int) ($event['bookedOffline'] ?? 0));

        return $offline + self::paidFor($event);
    }

    public static function spotsLeft(array $event): int
    {
        return max(0, self::capacity($event) - self::taken($event));
    }

    /** Spots paid for online for one class. */
    public static function paidFor(array $event): int
    {
        $seats = 0;
        foreach (self::paidOrders((int) ($event['id'] ?? 0)) as $order) {
            if (self::orderBelongsTo($order, $event)) {
                $seats += $order->seatCount();
            }
        }

        return $seats;
    }

    /**
     * Spots paid for online, per class id — what /api/availability serves.
     *
     * Ids the site no longer lists are passed through untouched: nothing shows
     * them, and there is no current class to match an order against.
     *
     * @return array<string,int>
     */
    public static function paidByClass(): array
    {
        $current = [];
        foreach (EventLinks::catalog()['events'] as $event) {
            if (isset($event['id'])) {
                $current[(string) $event['id']] = $event;
            }
        }

        $taken = [];
        foreach (self::paidOrders() as $order) {
            $id = (string) $order->event_id;
            $event = $current[$id] ?? null;
            if ($event !== null && ! self::orderBelongsTo($order, $event)) {
                continue;
            }
            $taken[$id] = ($taken[$id] ?? 0) + $order->seatCount();
        }

        return $taken;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Lead> */
    private static function paidOrders(?int $eventId = null)
    {
        $columns = ['event_id', 'event_name', 'event_date'];
        if (Lead::tracksSeats()) {
            $columns[] = 'seats';
        }

        return Lead::where('payment_status', 'paid')
            ->when($eventId !== null, fn ($query) => $query->where('event_id', $eventId))
            ->get($columns);
    }

    /**
     * Does this paid order belong to the class that holds its id today?
     *
     * A matching title or a matching date is enough on its own, so fixing a
     * typo in the title keeps the orders, and so does moving the class to
     * another date. Orders carrying neither field are kept — nothing proves
     * they are stale, and losing a real seat oversells the class.
     */
    private static function orderBelongsTo(Lead $order, array $event): bool
    {
        $orderName = self::textKey((string) $order->event_name);
        $orderDate = self::dateKey((string) $order->event_date);

        if ($orderName === '' && $orderDate === '') {
            return true;
        }
        if ($orderName !== '' && $orderName === self::textKey((string) ($event['eventName'] ?? ''))) {
            return true;
        }

        return $orderDate !== '' && $orderDate === self::dateKey(self::dateLabel($event));
    }

    /** The date string the sign-up form sends, e.g. "August 17 (Monday)". Mirrors OrderModal.getFormattedEventDateForPayload(). */
    private static function dateLabel(array $event): string
    {
        $day  = trim((string) ($event['day'] ?? ''), " \t\n\r\0\x0B,");
        $date = (string) ($event['date'] ?? '');

        if ($date === '' || $date === '%') {
            return $day !== '' ? 'Every ' . $day : 'Every day';
        }

        try {
            $label = Carbon::parse($date)->format('F j');
        } catch (\Throwable $e) {
            return $date;
        }

        return $day !== '' ? "{$label} ({$day})" : $label;
    }

    /** "August 17 (Monday)" and "August 17" compare equal — the weekday is decoration. */
    private static function dateKey(string $label): string
    {
        return self::textKey((string) preg_replace('/\(.*?\)/u', ' ', $label));
    }

    /** Case, spacing and punctuation must not decide whether an order counts. */
    private static function textKey(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($text), 'UTF-8'));
    }
}
