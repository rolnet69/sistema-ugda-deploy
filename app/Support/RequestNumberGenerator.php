<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class RequestNumberGenerator
{
    public static function transfer(CarbonInterface $requestDate): string
    {
        return self::build(
            modelClass: \App\Models\Transfer::class,
            column: 'code',
            dateColumn: 'request_date',
            prefix: 'TRF',
            requestDate: $requestDate,
        );
    }

    public static function loan(CarbonInterface $requestDate): string
    {
        return self::build(
            modelClass: \App\Models\Loan::class,
            column: 'number',
            dateColumn: 'requested_at',
            prefix: 'PRT',
            requestDate: $requestDate,
        );
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private static function build(string $modelClass, string $column, string $dateColumn, string $prefix, CarbonInterface $requestDate): string
    {
        $dateCode = $requestDate->format('dmy');
        $numberPrefix = "{$prefix}-{$dateCode}-";

        $last = $modelClass::query()
            ->whereDate($dateColumn, $requestDate->toDateString())
            ->where($column, 'like', $numberPrefix . '%')
            ->lockForUpdate()
            ->pluck($column)
            ->map(fn ($value) => (int) str_replace($numberPrefix, '', (string) $value))
            ->max();

        return $numberPrefix . str_pad((string) (($last ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }
}
