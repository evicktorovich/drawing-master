<?php

namespace App\Http\Controllers;

use App\Support\ClassAnnouncer;
use App\Support\ClassSeats;
use App\Support\EventLinks;
use App\Support\ReviewRequester;
use Carbon\Carbon;
use Illuminate\Http\Response;

/**
 * Pages for people who search Google for classes in Calgary.
 *
 * Google sends the studio paying students (2–3 bookings and form requests a
 * week in November 2026), but the site was a single page whose HTML holds no
 * text until the script runs. These pages are plain server-rendered HTML,
 * each aimed at one kind of search (Keyword Planner, Calgary, monthly:
 * "paint night calgary" 1 000, "art classes calgary" 880, "art lessons
 * calgary" 880, "painting classes calgary" 260, "watercolor classes calgary"
 * + "watercolour" 220, "art classes for adults calgary" 110).
 *
 * Every fact comes from the site itself (siteText in content.json, the class
 * list, prices) — nothing is made up. Upcoming classes are read live, so the
 * pages stay current without anyone editing them.
 */
class ClassPagesController extends Controller
{
    public const PAGES = [
        'art-classes-calgary' => [
            'title' => 'Art Classes in Calgary for Adults | Shuhai Art Studio',
            'h1' => 'Art classes in Calgary',
            'description' => 'Painting and drawing classes for adults in Calgary with artist Alevtyna: watercolor and acrylic workshops, regular classes and private lessons. Beginner-friendly, all materials included.',
            'intro' => [
                'Shuhai Art Studio is a small art studio at 1324 11 Ave SW, Calgary, run by Alevtyna — a professional fine arts teacher with over 12 years of teaching experience.',
                'Come to a one-evening workshop and take home a finished painting, join regular classes to build your skills step by step, or book private lessons. Beginners are welcome: every class comes with step-by-step instruction and individual guidance, and all materials are included.',
            ],
            'filter' => 'all',
            'listTitle' => 'Upcoming classes and workshops',
            'regular' => true,
        ],
        'watercolor-classes-calgary' => [
            'title' => 'Watercolor Classes in Calgary | Shuhai Art Studio',
            'h1' => 'Watercolor classes in Calgary',
            'description' => 'Beginner-friendly watercolor (watercolour) classes in Calgary with artist Alevtyna. Step-by-step guidance, all materials included, take your painting home.',
            'intro' => [
                'Watercolor is all about water, light and colour — and it is much easier to learn with a teacher next to you. In Alevtyna’s watercolor classes you work with washes, soft colour transitions and layering, step by step, and leave with a finished piece.',
                'No experience needed. All materials are provided, and Alevtyna guides every student individually, so beginners and people coming back to painting feel equally at home.',
            ],
            'filter' => 'watercolor',
            'listTitle' => 'Upcoming watercolor classes',
            'regular' => false,
        ],
        'acrylic-painting-classes-calgary' => [
            'title' => 'Acrylic Painting Classes in Calgary | Shuhai Art Studio',
            'h1' => 'Acrylic painting classes in Calgary',
            'description' => 'Acrylic painting workshops in Calgary for beginners and beyond: paint a picture from start to finish in one evening with artist Alevtyna. All materials included.',
            'intro' => [
                'In an acrylic workshop at Shuhai Art Studio you paint one picture from start to finish in about three hours, with step-by-step guidance from Alevtyna, a professional fine arts teacher.',
                'You don’t need any experience or supplies: high-quality materials are included, and you take your painting home at the end of the evening.',
            ],
            'filter' => 'acrylic',
            'listTitle' => 'Upcoming acrylic workshops',
            'regular' => false,
        ],
        'paint-night-calgary' => [
            'title' => 'Paint Night in Calgary — Evening Painting Workshops | Shuhai Art Studio',
            'h1' => 'Paint night in Calgary',
            'description' => 'Evening painting workshops in Calgary: a small group, step-by-step guidance from a professional artist, snacks and refreshments, and a finished painting to take home.',
            'intro' => [
                'Looking for something to do in the evening? Our painting workshops are about three hours long: a small group, step-by-step guidance from a professional artist, complimentary snacks and refreshments, and a finished painting to take home.',
                'Come on your own, with a friend or as a date. No experience needed — all materials are included.',
            ],
            'filter' => 'evening',
            'listTitle' => 'Upcoming evening workshops',
            'regular' => false,
        ],
        'private-art-lessons-calgary' => [
            'title' => 'Private Art Lessons in Calgary | Shuhai Art Studio',
            'h1' => 'Private art lessons in Calgary',
            'description' => 'Private art lessons in Calgary with Alevtyna, a professional fine arts teacher: painting, drawing and composition at your own pace, on weekdays.',
            'intro' => [
                'Private lessons with Alevtyna, a professional fine arts teacher with over 12 years of teaching experience. Each lesson is built around your interests and level — painting, drawing or composition.',
                'Alevtyna’s own style is realism, with academic training in painting, drawing and composition, and she is always happy to explore new styles and techniques with her students.',
            ],
            'filter' => 'none',
            'listTitle' => '',
            'regular' => true,
        ],
    ];

    public function show(string $page)
    {
        $cfg = self::PAGES[$page] ?? abort(404);
        $catalog = EventLinks::catalog();
        $text = self::siteText();
        $url = EventLinks::CANONICAL_BASE . '/' . $page;

        $others = [];
        foreach (self::PAGES as $slug => $p) {
            if ($slug !== $page) {
                $others[] = ['url' => '/' . $slug, 'label' => $p['h1']];
            }
        }

        return view('class-page', [
            'page'     => $page,
            'cfg'      => $cfg,
            'url'      => $url,
            'classes'  => $cfg['filter'] === 'none' ? [] : $this->upcoming($catalog['events'], $cfg['filter']),
            'regular'  => $cfg['regular'] ? $this->regular($catalog['regularClasses']) : [],
            'about'    => array_slice((array) ($text['aboutParagraphs'] ?? []), 0, 3),
            'faq'      => (array) ($text['faq'] ?? []),
            'email'    => (string) ($text['footerEmail'] ?? 'a.art.shuhai@gmail.com'),
            'others'   => $others,
            'reviews'  => EventLinks::CANONICAL_BASE . '/review',
            'jsonLd'   => $this->jsonLd($cfg, $url, (string) ($text['footerEmail'] ?? 'a.art.shuhai@gmail.com')),
        ]);
    }

    /** /sitemap.xml — the home page, these pages and every class that can still be booked. */
    public function sitemap(): Response
    {
        $base = EventLinks::CANONICAL_BASE;
        $urls = [$base . '/'];
        foreach (array_keys(self::PAGES) as $slug) {
            $urls[] = $base . '/' . $slug;
        }
        $catalog = EventLinks::catalog();
        foreach (['events', 'regularClasses'] as $key) {
            $list = $catalog[$key];
            $slugs = EventLinks::slugsFor($list);
            foreach ($list as $i => $item) {
                if (!EventLinks::isPast($item)) {
                    $urls[] = EventLinks::url($slugs[$i]);
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (array_unique($urls) as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u, ENT_XML1, 'UTF-8') . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * One-off classes still to come that fit the page, soonest first.
     *
     * @return array<int,array{name:string,when:string,price:string,img:string,url:string,soldOut:bool,desc:string}>
     */
    private function upcoming(array $events, string $filter): array
    {
        $slugs = EventLinks::slugsFor($events);
        $today = now(ClassAnnouncer::TZ)->format('Y-m-d');
        $out = [];
        foreach ($events as $i => $ev) {
            $date = (string) ($ev['date'] ?? '');
            $span = ReviewRequester::span($ev);
            // A running multi-week course stays listed only until it starts.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $today || !$this->fits($ev, $filter)) {
                continue;
            }
            $out[] = [
                'date'    => $date,
                'name'    => ClassAnnouncer::title((string) ($ev['eventName'] ?? '')),
                'when'    => ClassAnnouncer::when($ev),
                'price'   => ClassAnnouncer::price($ev['price'] ?? 0),
                'img'     => EventLinks::imageUrl($ev['img'] ?? null),
                'url'     => EventLinks::url($slugs[$i]),
                'soldOut' => ClassSeats::spotsLeft($ev) <= 0,
                'desc'    => self::clean((string) ($ev['modalDescription'] ?? ($ev['description'] ?? ''))),
                'course'  => $span !== null && $span[0] !== $span[1],
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    private function fits(array $ev, string $filter): bool
    {
        $name = mb_strtolower((string) ($ev['eventName'] ?? ''), 'UTF-8');
        return match ($filter) {
            'watercolor' => str_contains($name, 'watercolo'),
            'acrylic'    => str_contains($name, 'acrylic'),
            'evening'    => self::startsInEvening((string) ($ev['time'] ?? '')),
            default      => true,
        };
    }

    /** "6:00 pm - 9:00 pm" → true: starts at 5 pm or later. */
    private static function startsInEvening(string $time): bool
    {
        if (!preg_match('/^\s*(\d{1,2})(?::\d{2})?\s*(am|pm)/i', $time, $m)) {
            return false;
        }
        $hour = (int) $m[1] % 12 + (strtolower($m[2]) === 'pm' ? 12 : 0);
        return $hour >= 17;
    }

    /** Regular and private classes (no fixed date) — prices and days from the site. */
    private function regular(array $list): array
    {
        $slugs = EventLinks::slugsFor($list);
        $out = [];
        foreach ($list as $i => $item) {
            $per = trim((string) ($item['modalDiscount'] ?? ''));
            $time = trim((string) ($item['time'] ?? ''));
            $out[] = [
                'name'  => ClassAnnouncer::title((string) ($item['eventName'] ?? '')),
                'when'  => trim((string) ($item['day'] ?? '')) . ($time !== '' && $time !== '-' ? ', ' . str_replace(' - ', ' – ', $time) : ''),
                'price' => ClassAnnouncer::price($item['price'] ?? 0) . ($per !== '' ? ' ' . $per : ''),
                'desc'  => self::clean((string) ($item['modalDescription'] ?? '')),
                'url'   => EventLinks::url($slugs[$i]),
            ];
        }
        return $out;
    }

    private static function siteText(): array
    {
        $raw = @file_get_contents(public_path('content.json'));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data['siteText'] ?? null) ? $data['siteText'] : [];
    }

    /** Text from the CMS: tags and stray whitespace out; Cyrillic look-alike letters typed by mistake back to Latin. */
    private static function clean(string $s): string
    {
        $s = strtr(strip_tags($s), ['а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x', 'у' => 'y',
                                    'А' => 'A', 'Е' => 'E', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Х' => 'X']);
        return trim((string) preg_replace('/[ \t]+/u', ' ', $s));
    }

    private function jsonLd(array $cfg, string $url, string $email): string
    {
        $base = EventLinks::CANONICAL_BASE;
        $data = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'LocalBusiness',
                    '@id' => $base . '/#studio',
                    'name' => 'Shuhai Art Studio',
                    'url' => $base . '/',
                    'image' => $base . '/assets/img/bg-main-banner.webp',
                    'email' => $email,
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => '1324 11 Ave SW #202',
                        'addressLocality' => 'Calgary',
                        'addressRegion' => 'AB',
                        'postalCode' => 'T3C 0M6',
                        'addressCountry' => 'CA',
                    ],
                    'sameAs' => [
                        'https://www.instagram.com/shuhai_art_studio/',
                        'https://www.facebook.com/people/ART-Shuhai/61562003400987/',
                    ],
                ],
                [
                    '@type' => 'WebPage',
                    'url' => $url,
                    'name' => $cfg['title'],
                    'description' => $cfg['description'],
                    'about' => ['@id' => $base . '/#studio'],
                ],
            ],
        ];
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    }
}
