<?php

namespace App\Contracts;

interface GoogleIdTokenVerifier
{
    public function verify(string $idToken): ?array;
}
