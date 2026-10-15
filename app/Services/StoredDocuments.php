<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Read-only access to issued document PDFs in private storage.
 *
 * Whether a stored PDF is an OFFICIAL MODERN document is decided from the file
 * itself, never from dates, filenames, booking status or mere presence. The
 * modern templates write the PDF's embedded Title as "Invoice <invoice number>"
 * or "Payment Receipt <receipt number>"; the legacy templates wrote the generic
 * "Service Invoice" / "Service Receipt". A PDF counts as the modern official
 * document only when its Title names exactly this record's number, which also
 * ties the file to the record. Anything unreadable or unrecognised is treated as
 * NOT modern (the conservative answer). Nothing here writes, renames or
 * regenerates a file.
 */
class StoredDocuments
{
    /** Bytes of the file read to find the Info dictionary (dompdf writes it at the very start). */
    private const HEAD_BYTES = 8192;

    /** [disk, path] of a stored PDF, or null when the file does not exist. */
    public function locate(?string $path): ?array
    {
        $path = ltrim((string) $path, '/');

        if ($path === '') {
            return null;
        }

        if (Storage::disk('local')->exists($path)) {
            return ['local', $path];
        }

        // Older documents were written to the public disk as "storage/…".
        if (str_starts_with($path, 'storage/') && Storage::disk('public')->exists(substr($path, 8))) {
            return ['public', substr($path, 8)];
        }

        return null;
    }

    /**
     * The one path the generator writes for a record: invoices by invoice id, receipts by the
     * id of the booking the receipt belongs to (the paths the document generator
     * writes). Any other path is not that record's own document.
     *
     * @param 'invoice'|'receipt' $kind
     * @param int $recordKey invoice id, or the receipt's booking id
     */
    public function canonicalPath(string $kind, int $recordKey): string
    {
        return $kind === 'receipt'
            ? sprintf('documents/receipts/booking-%d-receipt.pdf', $recordKey)
            : sprintf('documents/invoices/invoice-%d.pdf', $recordKey);
    }

    /**
     * A matching Title alone is never enough. All of these must hold:
     *  1. the record's stored path is exactly its own canonical path (so the file is this record's,
     *     not another record's file or an arbitrary one);
     *  2. the file exists on the PRIVATE disk (legacy public-disk copies never qualify);
     *  3. the embedded Title names this document type and this record's number.
     * The caller has already resolved the record through the Owner's booking-scoped lookup.
     *
     * @param 'invoice'|'receipt' $kind
     * @param int $recordKey invoice id, or the receipt's booking id
     */
    public function isOfficialModern(?string $path, string $kind, ?string $number, int $recordKey): bool
    {
        if (blank($number)) {
            return false;
        }

        if (ltrim((string) $path, '/') !== $this->canonicalPath($kind, $recordKey)) {
            return false;
        }

        $located = $this->locate($path);

        if ($located === null || $located[0] !== 'local') {
            return false;
        }

        $title = $this->title($path);

        if ($title === null) {
            return false;
        }

        $expected = ($kind === 'receipt' ? 'Payment Receipt ' : 'Invoice ') . $number;

        return hash_equals($expected, $title);
    }

    /** The PDF's embedded Title, or null when the file is missing, not a PDF or has none. */
    public function title(?string $path): ?string
    {
        $located = $this->locate($path);

        if ($located === null) {
            return null;
        }

        $stream = Storage::disk($located[0])->readStream($located[1]);

        if (! is_resource($stream)) {
            return null;
        }

        $head = (string) fread($stream, self::HEAD_BYTES);
        fclose($stream);

        if (! str_starts_with($head, '%PDF-') || ! preg_match('/\/Title\s*\(((?:[^\\\\)]|\\\\.)*)\)/s', $head, $match)) {
            return null;
        }

        $raw = preg_replace('/\\\\(.)/s', '$1', $match[1]);

        // dompdf writes UTF-16BE with a byte-order mark; plain ASCII titles are also accepted.
        if (str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        $title = trim((string) $raw);

        return $title === '' ? null : $title;
    }
}
