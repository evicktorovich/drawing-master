<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $cfg['title'] }}</title>
    <meta name="description" content="{{ $cfg['description'] }}">
    <link rel="canonical" href="{{ $url }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Shuhai Art Studio">
    <meta property="og:title" content="{{ $cfg['title'] }}">
    <meta property="og:description" content="{{ $cfg['description'] }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:image" content="{{ $classes[0]['img'] ?? \App\Support\EventLinks::CANONICAL_BASE . '/assets/img/bg-main-banner.webp' }}">
    <meta name="twitter:card" content="summary_large_image">
    <script type="application/ld+json">{!! $jsonLd !!}</script>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --peach: #ffb785; --peach-soft: #fff1e7; --ink: #1f1f1f; --muted: #5c5c5c; --line: #ece4dd; --dark: #2d2d2d; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--ink); font-family: Montserrat, system-ui, sans-serif; font-size: 17px; line-height: 1.6; }
        a { color: inherit; }
        img { max-width: 100%; display: block; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 20px; }
        .top { background: var(--dark); }
        .top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-top: 14px; padding-bottom: 14px; }
        .top img { height: 52px; width: auto; }
        .btn { display: inline-block; background: var(--peach); color: #000; border-radius: 80px; padding: 12px 26px; font-weight: 500; font-size: 16px; text-decoration: none; white-space: nowrap; }
        .btn:hover { background: #ffa66b; }
        .btn-ghost { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.6); padding: 10px 22px; }
        .btn-ghost:hover { background: rgba(255,255,255,.1); }
        h1, h2, h3 { font-family: "Cormorant Garamond", Georgia, serif; font-weight: 500; line-height: 1.12; margin: 0 0 16px; }
        h1 { font-size: clamp(40px, 6vw, 64px); }
        h2 { font-size: clamp(30px, 4vw, 42px); }
        h3 { font-size: 26px; margin-bottom: 6px; }
        .hero { background: var(--peach-soft); padding: 56px 0 48px; }
        .hero p { max-width: 720px; margin: 0 0 14px; }
        .eyebrow { text-transform: uppercase; letter-spacing: .12em; font-size: 13px; color: var(--muted); margin-bottom: 10px !important; }
        .hero .btn { margin-top: 12px; }
        section { padding: 48px 0; }
        section + section { border-top: 1px solid var(--line); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 28px; }
        .card { border: 1px solid var(--line); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; background: #fff; }
        .card img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; }
        .card .body { padding: 18px 20px 22px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
        .card .when { color: var(--muted); font-size: 15px; }
        .card .price { font-weight: 600; }
        .card .desc { font-size: 15px; color: #333; margin: 4px 0 10px; }
        .card .btn { align-self: flex-start; margin-top: auto; }
        .sold { display: inline-block; font-size: 13px; font-weight: 600; letter-spacing: .06em; color: #b30000; }
        .plain { border: 1px solid var(--line); border-radius: 14px; padding: 20px 22px; }
        ul.ticks { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px 28px; }
        ul.ticks li { padding-left: 28px; position: relative; }
        ul.ticks li::before { content: ""; position: absolute; left: 0; top: .45em; width: 14px; height: 14px; border-radius: 50%; background: var(--peach); }
        .about p { max-width: 760px; }
        details { border-bottom: 1px solid var(--line); padding: 14px 0; max-width: 820px; }
        summary { cursor: pointer; font-weight: 600; }
        details p { margin: 10px 0 0; color: #333; }
        .visit { background: var(--peach); }
        .visit .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px 40px; }
        .visit p { margin: 0 0 8px; }
        .more a { display: inline-block; margin: 0 18px 10px 0; }
        footer { background: var(--dark); color: #ddd; font-size: 14px; padding: 22px 0; }
        footer a { color: #fff; }
        @media (max-width: 600px) {
            body { font-size: 16px; }
            .wrap { padding: 0 16px; }
            .top img { height: 42px; }
            .btn-ghost { padding: 8px 16px; font-size: 14px; }
            .hero { padding: 40px 0 36px; }
            section { padding: 38px 0; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
    @include('partials.gtm-head')
</head>
<body>
@include('partials.gtm-body')

<header class="top">
    <div class="wrap">
        <a href="/"><img src="/assets/img/logo-art.svg" alt="Shuhai Art Studio" width="85" height="52"></a>
        <a class="btn btn-ghost" href="/#events">All classes</a>
    </div>
</header>

<main>
    <div class="hero">
        <div class="wrap">
            <p class="eyebrow">Shuhai Art Studio · Calgary</p>
            <h1>{{ $cfg['h1'] }}</h1>
            @foreach ($cfg['intro'] as $p)
                <p>{{ $p }}</p>
            @endforeach
            @if ($classes)
                <a class="btn" href="#classes">See upcoming dates</a>
            @elseif ($regular)
                <a class="btn" href="#regular">See classes and prices</a>
            @endif
        </div>
    </div>

    @if ($cfg['filter'] !== 'none')
        <section id="classes">
            <div class="wrap">
                <h2>{{ $cfg['listTitle'] }}</h2>
                @if ($classes)
                    <div class="grid">
                        @foreach ($classes as $c)
                            <article class="card">
                                <a href="{{ $c['url'] }}"><img src="{{ $c['img'] }}" alt="{{ $c['name'] }}" loading="lazy"></a>
                                <div class="body">
                                    <h3>{{ $c['name'] }}</h3>
                                    <div class="when">{{ $c['when'] }}</div>
                                    <div class="price">{{ $c['price'] }}</div>
                                    @if ($c['desc'] !== '')
                                        <p class="desc">{{ \Illuminate\Support\Str::limit(explode("\n", $c['desc'])[0], 220) }}</p>
                                    @endif
                                    @if ($c['soldOut'])
                                        <span class="sold">SOLD OUT</span>
                                        <a class="btn" href="{{ $c['url'] }}">Join the waitlist</a>
                                    @else
                                        <a class="btn" href="{{ $c['url'] }}">Book your spot</a>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p>New dates are added regularly. <a href="/#events">See the full schedule</a> or follow the studio on <a href="https://www.instagram.com/shuhai_art_studio/">Instagram</a> to hear about the next one first.</p>
                @endif
            </div>
        </section>
    @endif

    @if ($regular)
        <section id="regular">
            <div class="wrap">
                <h2>Regular and private classes</h2>
                <div class="grid">
                    @foreach ($regular as $r)
                        <div class="plain">
                            <h3>{{ $r['name'] }}</h3>
                            @if (trim($r['when'], ' ,') !== '')
                                <div class="when">{{ trim($r['when'], ' ,') }}</div>
                            @endif
                            <p><strong>{{ preg_replace('/(\d),(\d)/', '$1.$2', $r['price']) }}</strong></p>
                            @if ($r['desc'] !== '')
                                <p>{{ $r['desc'] }}</p>
                            @endif
                            <a class="btn" href="{{ $r['url'] }}">Sign up</a>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($cfg['filter'] !== 'none')
        <section>
            <div class="wrap">
                <h2>Every workshop includes</h2>
                <ul class="ticks">
                    <li>All art materials, high quality</li>
                    <li>Step-by-step instruction and individual guidance</li>
                    <li>Complimentary snacks and refreshments</li>
                    <li>Your finished artwork to take home</li>
                </ul>
            </div>
        </section>
    @endif

    @if ($about)
        <section class="about">
            <div class="wrap">
                <h2>About Alevtyna</h2>
                @foreach ($about as $p)
                    <p>{{ $p }}</p>
                @endforeach
            </div>
        </section>
    @endif

    @if ($faq)
        <section>
            <div class="wrap">
                <h2>Questions</h2>
                @foreach ($faq as $q)
                    @if (!empty($q['question']) && !empty($q['answer']))
                        <details>
                            <summary>{{ $q['question'] }}</summary>
                            <p>{{ $q['answer'] }}</p>
                        </details>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    <section class="visit">
        <div class="wrap">
            <h2>Visit the studio</h2>
            <div class="cols">
                <div>
                    <p><strong>Shuhai Art Studio</strong></p>
                    <p>1324 11 Ave SW, #202<br>Calgary, AB T3C 0M6</p>
                    <p><a href="https://www.google.com/maps/search/?api=1&amp;query=Shuhai+Art+Studio+1324+11+Ave+SW+Calgary">Open in Google Maps</a></p>
                </div>
                <div>
                    <p><a href="mailto:{{ $email }}">{{ $email }}</a></p>
                    <p><a href="https://www.instagram.com/shuhai_art_studio/">Instagram</a> · <a href="https://www.facebook.com/people/ART-Shuhai/61562003400987/">Facebook</a></p>
                    <p><a href="https://search.google.com/local/reviews?placeid=ChIJjY9psgEV1AYREzNCD6ceLL8">Read reviews on Google</a></p>
                </div>
            </div>
        </div>
    </section>

    <nav class="more" aria-label="More classes">
        <section>
            <div class="wrap">
                <h2>More classes in Calgary</h2>
                @foreach ($others as $o)
                    <a href="{{ $o['url'] }}">{{ $o['label'] }}</a>
                @endforeach
                <a href="/">Shuhai Art Studio home</a>
            </div>
        </section>
    </nav>
</main>

<footer>
    <div class="wrap">© {{ date('Y') }} Shuhai Art Studio · 1324 11 Ave SW, #202, Calgary · <a href="/#events">All classes</a></div>
</footer>
</body>
</html>
