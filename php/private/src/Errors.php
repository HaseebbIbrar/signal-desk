<?php
declare(strict_types=1);

namespace SignalDesk;

class AppError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errCode, string $message)
    {
        parent::__construct($message);
    }
}

final class ValidationError extends AppError
{
    public function __construct(string $m) { parent::__construct(400, 'VALIDATION', $m); }
}

final class NotFoundError extends AppError
{
    public function __construct(string $m) { parent::__construct(404, 'NOT_FOUND', $m); }
}

final class ConflictError extends AppError
{
    public function __construct(string $m, string $code = 'CONFLICT') { parent::__construct(409, $code, $m); }
}

/** The workbook stayed locked/throttled after retries; the browser retries and says "Sheet busy, retrying…". */
final class SheetBusyError extends AppError
{
    public function __construct(string $m = 'Sheet busy, retrying…') { parent::__construct(503, 'SHEET_BUSY', $m); }
}

/** A write outside the allowed tab/columns/rows was attempted. This is a bug, never user error. */
final class ForbiddenWriteError extends AppError
{
    public function __construct(string $m) { parent::__construct(403, 'FORBIDDEN_WRITE', $m); }
}

final class UpstreamError extends AppError
{
    public function __construct(string $m) { parent::__construct(502, 'UPSTREAM', $m); }
}
