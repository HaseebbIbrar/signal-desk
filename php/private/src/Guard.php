<?php
// The single write gate. Every backend write method calls Guard::assert() before doing anything.
//
// The verify tab allows exactly four kinds of write (the Service enforces the business rules;
// this gate enforces the cells):
//   (a) Verify a signal                 -> row append      values A{r}:Q{r} | A{r}:R{r}, format A{r}
//   (b) Add another decision maker      -> row append      values A{r}:Q{r} | A{r}:R{r}, format A{r}
//       (A:R copied from the job's first verify row, new link in M, N:Q empty)
//   (c) Decision Maker LinkedIn         -> one cell        values M{r} on an identified row
//   (d) Untick (one row, M/S/T/U empty)  -> removal         clear A{r}:Q{r} | A{r}:R{r}, delete A{r}:{Q|R|U}{r}
//
//   Signals                         : never written.
//   Signals to verify decision make : values  A{r}:Q{r} | A{r}:R{r} | M{r}
//                                     format  A{r}
//                                     clear   A{r}:Q{r} | A{r}:R{r}
//                                     delete  A{r}:Q{r} | A{r}:R{r} | A{r}:U{r}
//                                     => S, T and U (and N:Q on their own) are never written with a value.
//   Decision Makers                 : values  A{r}:Q{r}  (row append)
//                                     format  A{r} | C{r} (Date Verified / Date Found)
//                                     clear   A{r}:Q{r}  (undo of the app's own last row)
//   Anything else                   : never written. Row 1 (headers) is never written anywhere.
declare(strict_types=1);

namespace SignalDesk;

final class Guard
{
    private const ALLOWED = [
        Sheet::VERIFY => [
            'values' => ['A:Q', 'A:R', 'M:M'],
            'format' => ['A:A'],
            'clear' => ['A:Q', 'A:R'],
            'delete' => ['A:Q', 'A:R', 'A:U'],
        ],
        Sheet::DECISION_MAKERS => [
            'values' => ['A:Q'],
            'format' => ['A:A', 'C:C'],
            'clear' => ['A:Q'],
        ],
    ];

    /** @return int the (single) row number the write targets */
    public static function assert(string $op, string $sheet, string $address): int
    {
        if ($sheet === Sheet::SIGNALS) throw new ForbiddenWriteError('Refused: the app never writes to the "Signals" tab');
        if (!isset(self::ALLOWED[$sheet])) throw new ForbiddenWriteError("Refused: the app never writes to the \"$sheet\" tab");
        if (str_contains($address, '!')) throw new ForbiddenWriteError('Refused: write addresses must not name a sheet');
        try {
            [$r1, $c1, $r2, $c2] = A1::parse($address);
        } catch (\InvalidArgumentException) {
            throw new ForbiddenWriteError("Refused: invalid address \"$address\"");
        }
        if ($r1 !== $r2) throw new ForbiddenWriteError('Refused: writes are limited to a single row');
        if ($r1 < 2) throw new ForbiddenWriteError('Refused: the header row is never written');
        $span = A1::indexToCol($c1) . ':' . A1::indexToCol($c2);
        $allowed = self::ALLOWED[$sheet][$op] ?? [];
        if (!in_array($span, $allowed, true)) {
            throw new ForbiddenWriteError("Refused: \"$op\" on columns $span of \"$sheet\" is not allowed");
        }
        return $r1;
    }
}
