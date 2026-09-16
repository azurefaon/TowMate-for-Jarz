<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_SERVER_KEY'),
        'browser_key' => env('GOOGLE_MAPS_API_KEY'),
        'geocode_url' => env('GOOGLE_MAPS_GEOCODE_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
        'routes_url' => env('GOOGLE_MAPS_ROUTES_URL', 'https://routes.googleapis.com/directions/v2:computeRoutes'),
        'places_autocomplete_url' => env('GOOGLE_PLACES_AUTOCOMPLETE_URL', 'https://places.googleapis.com/v1/places:autocomplete'),
        'places_details_url' => env('GOOGLE_PLACES_DETAILS_URL', 'https://places.googleapis.com/v1/places'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'openrouteservice' => [
        'key' => env('OPENROUTESERVICE_API_KEY'),
        'base_url' => env('OPENROUTESERVICE_BASE_URL', 'https://api.openrouteservice.org'),
    ],

    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'base_url'   => 'https://api.paymongo.com/v1',
    ],

];
