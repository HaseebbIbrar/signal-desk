<?php
// Workbook backends: MemoryBackend (tests + mock mode, optionally persisted to a JSON file) and
// GraphBackend (Microsoft Graph Excel API with app-only client credentials over PHP curl).
declare(strict_types=1);

namespace SignalDesk;

interface WorkbookBackend
{
    /** Label for the banner: "MOCK DATA", "DEV COPY" or "" (production). */
    public function label(): string;

    /** @return array{startRow:int,startCol:int,values:array<int,array<int,mixed>>} values-only used range */
    public function usedRange(string $sheet, ?string $sessionId = null): array;

    /** @return array<int,array<int,mixed>> */
    public function range(string $sheet, string $address, ?string $sessionId = null): array;

    /** Run $fn inside a workbook session (persistChanges = true). */
    public function withSession(callable $fn): mixed;

    public function patchValues(string $sheet, string $address, array $values, string $sessionId): void;
    public function patchNumberFormat(string $sheet, string $address, array $formats, string $sessionId): void;
    public function clearContents(string $sheet, string $address, string $sessionId): void;
    public function deleteShiftUp(string $sheet, string $address, string $sessionId): void;
}

/** In-memory workbook. With $file set, the grid is loaded from and saved to that JSON file (mock mode). */
final class MemoryBackend implements WorkbookBackend
{
    /** @var array<string,array<int,array<int,mixed>>> grid[sheet][rowIndex0][colIndex0] */
    public array $grid;
    /** @var array<string,string> "sheet!col:row" => number format */
    public array $formats = [];
    /** @var array<int,array{op:string,sheet:string,address:string}> */
    public array $writes = [];
    /** @var AppError[] errors thrown by the next calls (FIFO) */
    public array $failures = [];
    private int $sessions = 0;

    public function __construct(array $sheets, private string $labelText = 'MOCK DATA', private ?string $file = null)
    {
        $this->grid = $sheets;
    }

    /** Mock mode: load the grid from $file, seeding it from the fixture on first use. */
    public static function persisted(string $file, callable $seed): self
    {
        if (!is_file($file)) {
            JsonStore::write($file, ['sheets' => $seed(), 'formats' => []]);
        }
        $data = JsonStore::read($file, ['sheets' => [], 'formats' => []]);
        $b = new self($data['sheets'], 'MOCK DATA', $file);
        $b->formats = $data['formats'] ?? [];
        return $b;
    }

    public function label(): string { return $this->labelText; }

    private function maybeFail(): void
    {
        if ($this->failures) throw array_shift($this->failures);
    }

    private function save(): void
    {
        if ($this->file !== null) JsonStore::write($this->file, ['sheets' => $this->grid, 'formats' => $this->formats]);
    }

    private function sheet(string $name): array
    {
        if (!isset($this->grid[$name])) throw new UpstreamError("No such worksheet: $name");
        return $this->grid[$name];
    }

    private function cell(string $name, int $r, int $c): mixed
    {
        return $this->grid[$name][$r][$c] ?? null;
    }

    private function setCell(string $name, int $r, int $c, mixed $v): void
    {
        while (count($this->grid[$name]) <= $r) $this->grid[$name][] = [];
        while (count($this->grid[$name][$r]) <= $c) $this->grid[$name][$r][] = null;
        $this->grid[$name][$r][$c] = $v;
    }

    public function usedRange(string $sheet, ?string $sessionId = null): array
    {
        $this->maybeFail();
        $g = $this->sheet($sheet);
        $lastRow = -1;
        $lastCol = -1;
        foreach ($g as $r => $row) foreach ($row as $c => $v) {
            if (!Sheet::isBlank($v)) {
                $lastRow = max($lastRow, $r);
                $lastCol = max($lastCol, $c);
            }
        }
        if ($lastRow < 0) return ['startRow' => 1, 'startCol' => 0, 'values' => [['']]];
        $values = [];
        for ($r = 0; $r <= $lastRow; $r++) {
            $row = [];
            for ($c = 0; $c <= $lastCol; $c++) $row[] = $this->cell($sheet, $r, $c) ?? '';
            $values[] = $row;
        }
        return ['startRow' => 1, 'startCol' => 0, 'values' => $values];
    }

    public function range(string $sheet, string $address, ?string $sessionId = null): array
    {
        $this->maybeFail();
        [$r1, $c1, $r2, $c2] = A1::parse($address);
        $this->sheet($sheet);
        $out = [];
        for ($r = $r1 - 1; $r <= $r2 - 1; $r++) {
            $row = [];
            for ($c = $c1; $c <= $c2; $c++) $row[] = $this->cell($sheet, $r, $c) ?? '';
            $out[] = $row;
        }
        return $out;
    }

    public function withSession(callable $fn): mixed
    {
        $this->maybeFail();
        return $fn('mem-session-' . (++$this->sessions));
    }

    public function patchValues(string $sheet, string $address, array $values, string $sessionId): void
    {
        Guard::assert('values', $sheet, $address);
        $this->maybeFail();
        [$r1, $c1, , $c2] = A1::parse($address);
        $this->writes[] = ['op' => 'patchValues', 'sheet' => $sheet, 'address' => $address];
        for ($i = 0; $i <= $c2 - $c1; $i++) {
            $v = $values[0][$i] ?? '';
            // Excel treats a leading apostrophe as a "store as text" marker and does not keep it.
            if (is_string($v) && str_starts_with($v, "'")) $v = substr($v, 1);
            $this->setCell($sheet, $r1 - 1, $c1 + $i, $v);
        }
        $this->save();
    }

    public function patchNumberFormat(string $sheet, string $address, array $formats, string $sessionId): void
    {
        Guard::assert('format', $sheet, $address);
        $this->maybeFail();
        [$r1, $c1] = A1::parse($address);
        $this->writes[] = ['op' => 'patchNumberFormat', 'sheet' => $sheet, 'address' => $address];
        $this->formats["$sheet!$c1:$r1"] = $formats[0][0] ?? 'General';
        $this->save();
    }

    public function clearContents(string $sheet, string $address, string $sessionId): void
    {
        Guard::assert('clear', $sheet, $address);
        $this->maybeFail();
        [$r1, $c1, , $c2] = A1::parse($address);
        $this->writes[] = ['op' => 'clearContents', 'sheet' => $sheet, 'address' => $address];
        for ($c = $c1; $c <= $c2; $c++) $this->setCell($sheet, $r1 - 1, $c, null);
        $this->save();
    }

    public function deleteShiftUp(string $sheet, string $address, string $sessionId): void
    {
        Guard::assert('delete', $sheet, $address);
        $this->maybeFail();
        [$r1, $c1, , $c2] = A1::parse($address);
        $this->writes[] = ['op' => 'deleteShiftUp', 'sheet' => $sheet, 'address' => $address];
        $n = count($this->grid[$sheet]);
        for ($c = $c1; $c <= $c2; $c++) {
            for ($r = $r1 - 1; $r < $n; $r++) $this->setCell($sheet, $r, $c, $this->cell($sheet, $r + 1, $c));
        }
        $this->save();
    }
}

/** Microsoft Graph Excel API (cells edited in place; the file is never downloaded or re-uploaded). */
final class GraphBackend implements WorkbookBackend
{
    /** Locked / throttled / temporarily unavailable (e.g. while the nightly job saves the file). */
    public const RETRYABLE = [409, 423, 429, 503, 504];
    /** For the non-idempotent range delete, only retry when Graph clearly did NOT apply the request. */
    private const RETRYABLE_NOT_APPLIED = [409, 423, 429];

    private bool $targetChecked = false;
    /** @var callable|null test hook: fn(string $method, string $url, array $headers, ?string $body): array{status:int,headers:array,body:string} */
    public $transport = null;
    /** @var callable */
    public $sleep;

    public function __construct(
        private string $tenantId,
        private string $clientId,
        private string $clientSecret,
        private string $driveId,
        private string $itemId,
        private string $labelText,
        private string $tokenCacheFile,
        private ?string $forbiddenFileName = null,
        private int $maxAttempts = 4,
    ) {
        $this->sleep = fn (int $ms) => usleep($ms * 1000);
    }

    public function label(): string { return $this->labelText; }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function http(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport) return ($this->transport)($method, $url, $headers, $body);
        $ch = curl_init($url);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new UpstreamError('Could not reach Microsoft (' . $err . ')');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out];
    }

    private function token(bool $forceNew = false): string
    {
        if (!$forceNew) {
            $cached = JsonStore::read($this->tokenCacheFile, []);
            if (($cached['client'] ?? '') === $this->clientId && ($cached['expires'] ?? 0) > time() + 120) return (string) $cached['token'];
        }
        $res = $this->http('POST', 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
            ]));
        if ($res['status'] !== 200) {
            Audit::event('graph_token_error', ['status' => $res['status']]);
            throw new UpstreamError('Could not authenticate to Microsoft Graph (check tenant/client id and secret)');
        }
        $j = json_decode($res['body'], true);
        $token = (string) ($j['access_token'] ?? '');
        // Cache file lives in storage/ (outside the web root or behind "Require all denied"), mode 0600.
        JsonStore::write($this->tokenCacheFile, ['client' => $this->clientId, 'token' => $token, 'expires' => time() + (int) ($j['expires_in'] ?? 3600)]);
        @chmod($this->tokenCacheFile, 0600);
        return $token;
    }

    private function itemBase(): string
    {
        return 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($this->driveId) . '/items/' . rawurlencode($this->itemId);
    }

    /** @var array<string,string>|null lower-cased trimmed name => real worksheet name */
    private ?array $sheetNames = null;

    /** The real worksheet name: exact for the fixed tabs, case/space-insensitive for Sheet::LOOSE_NAMES. */
    public function sheetName(string $sheet): string
    {
        if (!in_array($sheet, Sheet::LOOSE_NAMES, true)) return $sheet;
        if ($this->sheetNames === null) {
            $this->sheetNames = [];
            $r = $this->request('GET', '/workbook/worksheets?$select=name');
            foreach ($r['value'] ?? [] as $w) $this->sheetNames[strtolower(trim((string) ($w['name'] ?? '')))] = (string) $w['name'];
        }
        return $this->sheetNames[strtolower(trim($sheet))] ?? throw new UpstreamError("The \"$sheet\" tab was not found in the workbook. Create it (see DEPLOY-20I.md).");
    }

    private function ws(string $sheet): string
    {
        return "/workbook/worksheets('" . rawurlencode(str_replace("'", "''", $this->sheetName($sheet))) . "')";
    }

    private function rangePath(string $sheet, string $address): string
    {
        A1::parse($address); // validates before it goes into the URL
        return $this->ws($sheet) . "/range(address='" . $address . "')";
    }

    private function backoff(int $attempt): int
    {
        return min(4000, 500 * (2 ** ($attempt - 1))) + random_int(0, 250);
    }

    /** Dev safety net: the target must not be the production master file. */
    private function ensureTarget(): void
    {
        if ($this->targetChecked || $this->forbiddenFileName === null) return;
        $item = $this->request('GET', '?$select=name', null, null, true, true);
        if (($item['name'] ?? '') === $this->forbiddenFileName) {
            throw new UpstreamError('Dev mode refused: GRAPH_DEV_ITEM_ID points at the master workbook. Use a COPY.');
        }
        $this->targetChecked = true;
    }

    private function request(string $method, string $path, mixed $body = null, ?string $sessionId = null, bool $idempotent = true, bool $skipTargetCheck = false): mixed
    {
        if (!$skipTargetCheck) $this->ensureTarget();
        $retryOn = $idempotent ? self::RETRYABLE : self::RETRYABLE_NOT_APPLIED;
        $refreshed = false;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $headers = ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json', 'Accept: application/json'];
            if ($sessionId) $headers[] = 'workbook-session-id: ' . $sessionId;
            $res = $this->http($method, $this->itemBase() . $path, $headers, $body === null ? null : json_encode($body));
            $s = $res['status'];
            if ($s >= 200 && $s < 300) return $res['body'] === '' ? null : json_decode($res['body'], true);
            if ($s === 401 && !$refreshed) {
                $refreshed = true;
                $this->token(true);
                $attempt--;
                continue;
            }
            if (in_array($s, $retryOn, true) && $attempt < $this->maxAttempts) {
                $ra = (int) ($res['headers']['retry-after'] ?? 0);
                $wait = $ra > 0 ? min($ra * 1000, 5000) : $this->backoff($attempt);
                Audit::event('graph_retry', ['status' => $s, 'method' => $method, 'attempt' => $attempt, 'waitMs' => $wait]);
                ($this->sleep)($wait);
                continue;
            }
            if (in_array($s, self::RETRYABLE, true)) throw new SheetBusyError();
            Audit::event('graph_error', ['status' => $s, 'method' => $method, 'path' => $path, 'detail' => substr($res['body'], 0, 500)]);
            if ($s === 404) throw new UpstreamError('Workbook or worksheet not found. Check the GRAPH_* ids and tab names.');
            if ($s === 403) throw new UpstreamError('Microsoft Graph denied access. Check the Sites.Selected grant.');
            throw new UpstreamError("Microsoft Graph error $s");
        }
        throw new SheetBusyError();
    }

    public function usedRange(string $sheet, ?string $sessionId = null): array
    {
        $r = $this->request('GET', $this->ws($sheet) . '/usedRange(valuesOnly=true)?$select=address,values', null, $sessionId);
        [$row, $col] = A1::parse((string) $r['address']);
        return ['startRow' => $row, 'startCol' => $col, 'values' => $r['values'] ?? []];
    }

    public function range(string $sheet, string $address, ?string $sessionId = null): array
    {
        $r = $this->request('GET', $this->rangePath($sheet, $address) . '?$select=values', null, $sessionId);
        return $r['values'] ?? [];
    }

    public function withSession(callable $fn): mixed
    {
        $s = $this->request('POST', '/workbook/createSession', ['persistChanges' => true]);
        try {
            return $fn((string) $s['id']);
        } finally {
            try {
                $this->request('POST', '/workbook/closeSession', new \stdClass(), (string) $s['id'], false);
            } catch (\Throwable) {
                // best effort
            }
        }
    }

    public function patchValues(string $sheet, string $address, array $values, string $sessionId): void
    {
        Guard::assert('values', $sheet, $address);
        $this->request('PATCH', $this->rangePath($sheet, $address), ['values' => $values], $sessionId);
    }

    public function patchNumberFormat(string $sheet, string $address, array $formats, string $sessionId): void
    {
        Guard::assert('format', $sheet, $address);
        $this->request('PATCH', $this->rangePath($sheet, $address), ['numberFormat' => $formats], $sessionId);
    }

    public function clearContents(string $sheet, string $address, string $sessionId): void
    {
        Guard::assert('clear', $sheet, $address);
        $this->request('POST', $this->rangePath($sheet, $address) . '/clear', ['applyTo' => 'Contents'], $sessionId);
    }

    public function deleteShiftUp(string $sheet, string $address, string $sessionId): void
    {
        Guard::assert('delete', $sheet, $address);
        // Not idempotent: a blind retry after a "503 but actually applied" would delete the next row.
        $this->request('POST', $this->rangePath($sheet, $address) . '/delete', ['shift' => 'Up'], $sessionId, false);
    }
}
