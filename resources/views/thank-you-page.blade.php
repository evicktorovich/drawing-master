<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class=" h-100">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">

        <title>Art Classes with Alevtyna</title>
        <meta name="description" content="Unlock creativity with inspiring art workshops for all skill levels.">
        <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
        <link rel="manifest" href="/site.webmanifest" />
        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Montserrat:wght@100..900&display=swap" rel="stylesheet">

        @include('partials.gtm-head')

        <!-- Purchase event → fires once on thank-you arrival. event_id = Stripe
             session id so Meta dedupes against the server-side CAPI hit. -->
        <script>
        (function(){
            var p = new URLSearchParams(window.location.search);
            var sid = p.get('session_id');
            if (!sid) return;
            var key = 'shuhai_purchase_fired_' + sid;
            try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch(e){}
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                event: 'purchase',
                event_id: sid,
                currency: 'CAD'
            });
        })();
        </script>

        <style>
            .modal-title {
                font-family: Cormorant Garamond, sans-serif;
                font-size: 42px;
                font-weight: 400;
            }
        </style>
    </head>
    <body class="antialiased h-100">
    @include('partials.gtm-body')

    <div id="app" class="h-100">
        <div class="container h-100 d-flex flex-column align-items-center justify-content-center">
            <div class="header">
                <div class="modal-title text-center">Your application has been accepted!</div>
            </div>
            <div class="body text-center">
                <img src="{{asset('assets/img/img-success.png')}}" alt="success" class="adaptive-img">
            </div>
        </div>
    </div>

    @vite('resources/js/app.js')
    </body>
</html>
