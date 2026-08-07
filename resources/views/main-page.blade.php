<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="facebook-domain-verification" content="5te71m0zbc6lwtrbw9cp3z5ih99nf7" />
        @php
            // Defaults for "/"; the /event/{slug} route overrides them per class.
            $base = \App\Support\EventLinks::CANONICAL_BASE;
            $meta = array_merge([
                'title' => 'Art Classes with Alevtyna',
                'description' => 'Unlock creativity with inspiring art workshops for all skill levels.',
                'image' => $base . '/assets/img/bg-main-banner.webp',
                'url' => $base . '/',
            ], $meta ?? []);
        @endphp
        <title>{{ $meta['title'] }}</title>
        <meta name="description" content="{{ $meta['description'] }}">
        <link rel="canonical" href="{{ $meta['url'] }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Shuhai Art Studio">
        <meta property="og:title" content="{{ $meta['title'] }}">
        <meta property="og:description" content="{{ $meta['description'] }}">
        <meta property="og:url" content="{{ $meta['url'] }}">
        <meta property="og:image" content="{{ $meta['image'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $meta['title'] }}">
        <meta name="twitter:description" content="{{ $meta['description'] }}">
        <meta name="twitter:image" content="{{ $meta['image'] }}">
        @isset($jsonLd)
            <script type="application/ld+json">{!! $jsonLd !!}</script>
        @endisset
        @isset($deepLink)
            <script>window.__DEEPLINK__ = {!! json_encode($deepLink, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};</script>
        @endisset
        <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
        <link rel="manifest" href="/site.webmanifest" />

        <link rel="preload" href="/assets/img/bg-main-banner.webp" as="image" media="(min-width: 992px)">
        <link rel="preload" href="/assets/img/bg-mobile-banner.webp" as="image" media="(max-width: 991px)">
        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Montserrat:wght@100..900&display=swap" rel="stylesheet">
        
        @include('partials.gtm-head')
    </head>
    <body class="antialiased">
    @include('partials.gtm-body')

    <div id="app">
        <main-page></main-page>
        <footer-component></footer-component>
    </div>

    @vite('resources/js/app.js')
    </body>
</html>
