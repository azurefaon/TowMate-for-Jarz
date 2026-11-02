<?php

/*
 * Android App Links: the verified HTTPS addresses that open the TowMate app directly.
 *
 * The fingerprint below is the SHA-256 of the PUBLIC release signing certificate (it is published in
 * /.well-known/assetlinks.json by design). It is not a secret; the keystore and its passwords are never stored here.
 */
return [
    // The canonical public domain. Railway is only the host behind it.
    'base_url' => rtrim(env('APP_LINK_BASE_URL', 'https://www.jarztowing.com'), '/'),

    'android_package' => env('APP_LINK_ANDROID_PACKAGE', 'com.jarztowing.towmate'),

    // Comma separated "AA:BB:..." SHA-256 certificate fingerprints allowed to claim the links.
    'android_sha256_fingerprints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'APP_LINK_ANDROID_SHA256',
            'F6:BB:53:C6:E6:F8:30:BC:27:95:21:BD:A2:3C:65:7F:BB:E2:12:10:A8:FB:94:D2:31:81:24:A2:C1:DF:95:3A'
        ))
    ))),

    // Where the hosted checkout sends the customer back to (opens the app when the link is verified).
    'payment_return_path' => '/pay/return',
];
