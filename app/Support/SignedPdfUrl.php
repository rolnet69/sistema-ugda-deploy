<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

class SignedPdfUrl
{
    private const PREVIEW_TTL_MINUTES = 10;

    public static function transferDocumentReference(int $documentId): string
    {
        return 'document-' . $documentId;
    }

    public static function transferDocument(string $number, string $code): string
    {
        return URL::temporarySignedRoute(
            'transfers.documents.download',
            now()->addMinutes(self::PREVIEW_TTL_MINUTES),
            [
                'number' => $number,
                'code' => $code,
            ],
            false
        );
    }

    public static function transferSummarySheet(string $number): string
    {
        return URL::temporarySignedRoute(
            'transfers.summary-sheet',
            now()->addMinutes(self::PREVIEW_TTL_MINUTES),
            ['number' => $number],
            false
        );
    }

    public static function loanSummarySheet(string $number): string
    {
        return URL::temporarySignedRoute(
            'loans.summary-sheet',
            now()->addMinutes(self::PREVIEW_TTL_MINUTES),
            ['number' => $number],
            false
        );
    }
}
