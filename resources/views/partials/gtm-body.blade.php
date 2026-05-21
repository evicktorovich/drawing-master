@php
    $gtmId = config('services.gtm.container_id', env('GTM_CONTAINER_ID', 'GTM-WR69S8GH'));
@endphp
@if($gtmId)
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
@endif
