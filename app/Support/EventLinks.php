<?php

namespace App\Support;

/**
 * Per-event permalinks: /event/{slug}.
 *
 * The slug comes from the class title, and only gets the date appended when
 * two classes share a title, so a one-off class keeps a short readable link
 * and a repeating one still gets a unique link per date.
 *
 * The exact same rules are implemented in resources/js/eventLinks.js (site)
 * and in public/admin/index.html (CMS "copy link" button). If you change the
 * rules here, change them in all three places or links stop matching.
 */
class EventLinks
{
    /** Mirrors RedirectToCanonicalHost::CANONICAL_HOST. */
    public const CANONICAL_BASE = 'https://art-shuhai.com';

    /** Fallback for slugify() when ext-intl is missing. */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
        'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ť' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ų' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /**
     * Accents are dropped the same way JS does it (decompose, then drop the
     * accent marks) — iconv//TRANSLIT is not used because its output differs
     * between systems, which would silently break links.
     */
    public static function slugify(string $text): string
    {
        $text = trim(mb_strtolower($text, 'UTF-8'));

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        } else {
            $text = strtr($text, self::ACCENTS);
        }

        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }

    /**
     * Slug for every item of a list, in list order.
     *
     * @param  array<int,array>  $list
     * @return array<int,string>
     */
    public static function slugsFor(array $list): array
    {
        $bases = [];
        foreach ($list as $item) {
            $base = self::slugify((string) ($item['eventName'] ?? ''));
            $bases[] = $base !== '' ? $base : 'class';
        }

        $counts = array_count_values($bases);
        $seen = [];
        $slugs = [];

        foreach ($bases as $i => $base) {
            $slug = $base;
            $date = (string) ($list[$i]['date'] ?? '');
            if (($counts[$base] ?? 0) > 1 && $date !== '' && $date !== '%') {
                $slug = $base . '-' . self::slugify($date);
            }
            // Same title AND same date — fall back to a counter so links stay unique.
            $n = ($seen[$slug] ?? 0) + 1;
            $seen[$slug] = $n;
            $slugs[] = $n > 1 ? $slug . '-' . $n : $slug;
        }

        return $slugs;
    }

    /**
     * Events and regular classes as the public site sees them (CMS content.json).
     *
     * @return array{events: array<int,array>, regularClasses: array<int,array>}
     */
    public static function catalog(): array
    {
        $raw = @file_get_contents(public_path('content.json'));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['events' => [], 'regularClasses' => []];
        }

        return [
            'events' => array_values(array_filter((array) ($data['events'] ?? []), 'is_array')),
            'regularClasses' => array_values(array_filter((array) ($data['regularClasses'] ?? []), 'is_array')),
        ];
    }

    /**
     * Find the class a link points at.
     *
     * Falls back to the title-only slug (without the date) so an older link to
     * a repeating class lands on its next upcoming date instead of a dead end.
     *
     * @return array{event: array, type: string, slug: string}|null
     */
    public static function resolve(string $slug): ?array
    {
        $slug = self::slugify($slug);
        if ($slug === '') {
            return null;
        }

        $catalog = self::catalog();
        $lists = [
            ['events', 'event'],
            ['regularClasses', 'infinityEvent'],
        ];

        foreach ($lists as [$key, $type]) {
            $list = $catalog[$key];
            $slugs = self::slugsFor($list);
            foreach ($list as $i => $item) {
                if ($slugs[$i] === $slug) {
                    return ['event' => $item, 'type' => $type, 'slug' => $slugs[$i]];
                }
            }
        }

        $best = null;
        foreach ($lists as [$key, $type]) {
            $list = $catalog[$key];
            $slugs = self::slugsFor($list);
            foreach ($list as $i => $item) {
                if (self::slugify((string) ($item['eventName'] ?? '')) !== $slug || self::isPast($item)) {
                    continue;
                }
                $date = (string) ($item['date'] ?? '');
                if ($best === null || $date < (string) ($best['event']['date'] ?? '')) {
                    $best = ['event' => $item, 'type' => $type, 'slug' => $slugs[$i]];
                }
            }
        }

        return $best;
    }

    /** Recurring classes ('%' date) never expire. */
    public static function isPast(array $event): bool
    {
        $date = (string) ($event['date'] ?? '');
        if ($date === '' || $date === '%') {
            return false;
        }

        return $date < now('America/Edmonton')->format('Y-m-d');
    }

    /** Mirrors MainPage.imgSrc() / the admin's canonicalImgUrl(). */
    public static function imageUrl(?string $img): string
    {
        $img = (string) $img;
        if ($img === '') {
            return self::CANONICAL_BASE . '/assets/img/bg-main-banner.webp';
        }
        if (preg_match('#^https?://#i', $img)) {
            return $img;
        }
        $path = str_starts_with($img, 'assets/') ? '/' . $img : '/assets/img/' . $img;

        return self::CANONICAL_BASE . $path;
    }

    public static function url(string $slug): string
    {
        return self::CANONICAL_BASE . '/event/' . $slug;
    }
}
