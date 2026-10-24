<?php

namespace App\Mail;

class DocumentFilename
{
    /** "Invoice INV-1001.pdf": a readable attachment name that is safe on every mail client and file system. */
    public static function for(string $kind, ?string $number): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $number), '-');

        return $kind . ($safe !== '' ? ' ' . $safe : '') . '.pdf';
    }
}
