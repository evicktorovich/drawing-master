<?php

namespace App\Http\Controllers;

use App\Support\EventLinks;
use Carbon\Carbon;

/**
 * Shareable page per class: /event/{slug}.
 *
 * Same single-page app as "/", but with the title, description, social preview
 * and Event schema of one class, and a hint for the front end to open that
 * class's sign-up popup on load.
 */
class EventPageController extends Controller
{
    public function show(string $slug)
    {
        $hit = EventLinks::resolve($slug);

        // Unknown link, or the class already happened — send people to what's on now.
        // Absolute https on purpose: behind Railway's proxy a relative redirect
        // comes back as http:// and costs the visitor an extra hop.
        if (!$hit || EventLinks::isPast($hit['event'])) {
            return redirect(EventLinks::CANONICAL_BASE . '/#events');
        }
        if ($hit['slug'] !== $slug) {
            return redirect(EventLinks::url($hit['slug']), 301);
        }

        $event = $hit['event'];
        $name = trim((string) ($event['eventName'] ?? 'Art class'));
        $url = EventLinks::url($hit['slug']);
        $image = EventLinks::imageUrl($event['img'] ?? null);
        $when = $this->humanDate($event);

        $meta = [
            'title' => $when !== '' ? "{$name} — {$when} | Shuhai Art Studio" : "{$name} | Shuhai Art Studio",
            'description' => $this->description($event),
            'image' => $image,
            'url' => $url,
        ];

        return view('main-page', [
            'meta' => $meta,
            'jsonLd' => $this->jsonLd($event, $name, $url, $image),
            'deepLink' => [
                'slug' => $hit['slug'],
                'type' => $hit['type'],
                'id' => $event['id'] ?? null,
            ],
        ]);
    }

    private function humanDate(array $event): string
    {
        $date = (string) ($event['date'] ?? '');
        if ($date === '' || $date === '%') {
            $day = trim((string) ($event['day'] ?? ''), " \t\n\r\0\x0B,");

            return $day !== '' ? "every {$day}" : '';
        }

        try {
            return Carbon::parse($date)->format('F j, Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    private function description(array $event): string
    {
        $text = trim(strip_tags((string) ($event['modalDescription'] ?? '')));
        if ($text === '') {
            $text = trim(strip_tags((string) ($event['description'] ?? '')));
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        $when = $this->humanDate($event);
        $price = $event['price'] ?? null;
        $facts = array_filter([
            $when !== '' ? ucfirst($when) : '',
            (string) ($event['time'] ?? ''),
            is_numeric($price) ? '$' . rtrim(rtrim(number_format((float) $price, 2, '.', ''), '0'), '.') : '',
        ]);
        $prefix = $facts ? implode(' · ', $facts) . '. ' : '';

        $out = $prefix . $text;
        if (mb_strlen($out) > 300) {
            $out = mb_substr($out, 0, 297) . '…';
        }

        return $out !== '' ? $out : 'Art workshops in Calgary for all skill levels.';
    }

    /** schema.org Event, so the class can surface in search and AI answers. */
    private function jsonLd(array $event, string $name, string $url, string $image): ?string
    {
        $date = (string) ($event['date'] ?? '');
        if ($date === '' || $date === '%') {
            return null; // Recurring classes have no single start time.
        }

        [$start, $end] = $this->window($date, (string) ($event['time'] ?? ''));
        $location = trim((string) ($event['location'] ?? ''));
        $parts = array_map('trim', explode(',', $location));
        $city = count($parts) > 1 ? array_pop($parts) : 'Calgary';
        $street = $parts ? implode(', ', $parts) : $location;

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $name,
            'url' => $url,
            'image' => [$image],
            'description' => $this->description($event),
            'startDate' => $start,
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => [
                '@type' => 'Place',
                'name' => 'Shuhai Art Studio',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $street,
                    'addressLocality' => $city,
                    'addressRegion' => 'AB',
                    'addressCountry' => 'CA',
                ],
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => 'Shuhai Art Studio',
                'url' => EventLinks::CANONICAL_BASE,
            ],
            'performer' => [
                '@type' => 'Person',
                'name' => 'Alevtyna Shuhai',
            ],
        ];

        if ($end !== null) {
            $data['endDate'] = $end;
        }
        if (is_numeric($event['price'] ?? null)) {
            $data['offers'] = [
                '@type' => 'Offer',
                'price' => (float) $event['price'],
                'priceCurrency' => 'CAD',
                'availability' => 'https://schema.org/InStock',
                'url' => $url,
            ];
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * "6:00 pm - 9:00 pm" → ISO start/end in Calgary time.
     *
     * @return array{0:string,1:?string}
     */
    private function window(string $date, string $time): array
    {
        $tz = 'America/Edmonton';
        $parts = preg_split('/\s*[-–—]\s*/u', trim($time)) ?: [];

        $parse = function (?string $clock) use ($date, $tz): ?string {
            $clock = trim((string) $clock);
            if ($clock === '') {
                return null;
            }
            try {
                return Carbon::parse("{$date} {$clock}", $tz)->toIso8601String();
            } catch (\Throwable $e) {
                return null;
            }
        };

        $start = $parse($parts[0] ?? null) ?? $date;
        $end = $parse($parts[1] ?? null);

        return [$start, $end];
    }
}
