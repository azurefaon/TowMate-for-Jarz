<?php

use App\Services\Auth\GoogleApiClientIdTokenVerifier;

it('fails closed on an empty id token, without any network call', function () {
    config(['services.google.client_id' => 'some-client-id.apps.googleusercontent.com']);

    $verifier = new GoogleApiClientIdTokenVerifier();

    expect($verifier->verify(''))->toBeNull();
    expect($verifier->verify('   '))->toBeNull();
});

it('fails closed on a malformed (non-JWT) token, without any network call', function () {
    config(['services.google.client_id' => 'some-client-id.apps.googleusercontent.com']);

    $verifier = new GoogleApiClientIdTokenVerifier();

    expect($verifier->verify('not-a-real-jwt'))->toBeNull();
    expect($verifier->verify('a.b'))->toBeNull();
});

it('fails closed when GOOGLE_CLIENT_ID is not configured, rather than accepting any token', function () {
    config(['services.google.client_id' => '']);

    $verifier = new GoogleApiClientIdTokenVerifier();

    expect($verifier->verify('anything-at-all'))->toBeNull();
});
