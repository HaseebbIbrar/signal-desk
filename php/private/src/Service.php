<?php
// Signal Desk business logic: 3 stages.
//   1. Signals: Verify (append to the verify tab) / Untick / Reject (app-side) / Undo reject.
//   2. Verified: read M/S/T/U; JP edits column M (LinkedIn) only.
//   3. Approve: append ONE row to "Decision Makers"; undo only the app's own last row.
// Rows are always located by Job URL right before writing, never by a remembered row number.
declare(strict_types=1);

namespace SignalDesk;

final class Service
{
    private Rejections $rejections;
    private string $receiptFile;
    private string $lockFile;
    private string $cacheDir;

    public function __construct(
        private WorkbookBackend $wb,
        private string $storageDir,
        private string $timezone = 'Europe/Brussels',
        private int $cacheTtl = 0,
    ) {
        $this->rejections = new Rejections($storageDir . '/rejections.json');
        $this->receiptFile = $storageDir . '/dm-receipts.json';
        $this->lockFile = $storageDir . '/write.lock';
        $this->cacheDir = $storageDir . '/cache';
    }

    // ------------------------------------------------------------------ reads

    private function width(string $sheet): int
    {
        return match ($sheet) {
            Sheet::SIGNALS => Sheet::SIGNALS_COLS,
            Sheet::VERIFY => Sheet::VERIFY_COLS,
            Sheet::DECISION_MAKERS => Sheet::DM_TAB_COLS,
        };
    }

    /** Rows of a tab, fresh from the backend. Foreign R (and S:U on the verify tab) are blanked. */
    private function readFresh(string $sheet, ?string $sid = null): array
    {
        $rows = Sheet::toRows($this->wb->usedRange($sheet, $sid), $this->width($sheet));
        if ($sheet === Sheet::DECISION_MAKERS) return $rows;
        return Sheet::withoutForeignColumns($rows, $sheet === Sheet::VERIFY);
    }

    /** Cached read (~60 s, Graph mode only). Writes invalidate the tab they wrote. */
    private function readCached(string $sheet): array
    {
        if ($this->cacheTtl <= 0) return $this->readFresh($sheet);
        $file = $this->cacheDir . '/' . md5($sheet) . '.json';
        $c = JsonStore::read($file, null);
        if (is_array($c) && ($c['time'] ?? 0) > time() - $this->cacheTtl) return $c['rows'];
        $rows = $this->readFresh($sheet);
        JsonStore::write($file, ['time' => time(), 'rows' => $rows]);
        return $rows;
    }

    private function invalidate(string $sheet): void
    {
        @unlink($this->cacheDir . '/' . md5($sheet) . '.json');
    }

    private static function key(array $row): string
    {
        return JobUrl::normalise($row['cells'][Sheet::JOB_URL] ?? null);
    }

    private static function dataRows(array $rows): array
    {
        return array_values(array_filter($rows, fn ($r) => $r['row'] >= 2 && Sheet::isDataRow($r['cells'])));
    }

    private static function matches(array $rows, string $key): array
    {
        return array_values(array_filter(self::dataRows($rows), fn ($r) => self::key($r) === $key));
    }

    private static function requireKey(mixed $jobUrl): string
    {
        $key = JobUrl::normalise(is_string($jobUrl) ? $jobUrl : null);
        if ($key === '') throw new ValidationError('A Job URL is required');
        if (strlen($key) > 2000) throw new ValidationError('Job URL is too long');
        return $key;
    }

    private static function d(array $cells, int $i): string
    {
        return Sheet::display($cells[$i] ?? null);
    }

    /** Verify-row state needed by the Signals page. */
    private static function mstuFilled(array $c): array
    {
        $filled = [];
        foreach ([Sheet::DM_LINKEDIN => 'M', Sheet::DM_NOTE => 'S', Sheet::DM_NAME => 'T', Sheet::DM_TITLE => 'U'] as $col => $letter) {
            if (!Sheet::isBlank($c[$col] ?? null)) $filled[] = $letter;
        }
        return $filled;
    }

    /**
     * Per job: verified if at least one verify row has its URL. Untick is allowed only when exactly one
     * verify row exists for the job and its M, S, T and U are all empty ("locked" otherwise).
     */
    private function verifyIndex(array $verifyRows): array
    {
        $idx = [];
        foreach (self::dataRows($verifyRows) as $r) {
            $k = self::key($r);
            if ($k === '') continue;
            $c = $r['cells'];
            $e = $idx[$k] ?? ['row' => $r['row'], 'rows' => 0, 'hasDM' => false, 'anyFilled' => false];
            $e['rows']++;
            if (!Sheet::isBlank($c[Sheet::DM_LINKEDIN] ?? null)) $e['hasDM'] = true;
            if (self::mstuFilled($c)) $e['anyFilled'] = true;
            $idx[$k] = $e;
        }
        foreach ($idx as &$e) $e['locked'] = $e['rows'] > 1 || $e['anyFilled'];
        return $idx;
    }

    public function signalsView(?string $date = null, bool $showRejected = false): array
    {
        $signalRows = $this->readCached(Sheet::SIGNALS);
        $verifyIdx = $this->verifyIndex($this->readCached(Sheet::VERIFY));
        $rejected = $this->rejections->all();
        $blocks = Sheet::blocks($signalRows);
        $dates = Sheet::blockDates($blocks);
        $latest = Sheet::latestDate($blocks);
        $selected = ($date && in_array($date, $dates, true)) ? $date : $latest;

        $rows = [];
        $total = 0;
        $ticked = 0;
        $rejectedCount = 0;
        foreach ($blocks as $b) {
            if ($b['date'] !== $selected) continue;
            foreach ($b['rows'] as $r) {
                $c = $r['cells'];
                $k = self::key($r);
                $v = $k !== '' ? ($verifyIdx[$k] ?? null) : null;
                $rej = $k !== '' ? ($rejected[$k] ?? null) : null;
                if ($rej) $rejectedCount++;
                else $total++;
                if ($v && !$rej) $ticked++;
                if ($rej && !$showRejected) continue;
                $rows[] = [
                    'rowNumber' => $r['row'], 'key' => $k, 'jobUrl' => trim(self::d($c, Sheet::JOB_URL)),
                    'dateFound' => ExcelDate::cellToIso($c[0]) ?? $b['date'] ?? self::d($c, 0),
                    'company' => self::d($c, Sheet::COMPANY), 'domain' => self::d($c, Sheet::DOMAIN),
                    'jobTitle' => self::d($c, Sheet::JOB_TITLE), 'matchedKeyword' => self::d($c, Sheet::MATCHED_KEYWORD),
                    'roleGroup' => self::d($c, Sheet::ROLE_GROUP), 'roleSubGroup' => self::d($c, Sheet::ROLE_SUB_GROUP),
                    'companyType' => self::d($c, Sheet::COMPANY_TYPE), 'location' => self::d($c, Sheet::LOCATION),
                    'status' => self::d($c, Sheet::STATUS), 'source' => self::d($c, Sheet::SOURCE),
                    'priorOutreach' => self::d($c, Sheet::PRIOR_OUTREACH), 'notes' => self::d($c, Sheet::NOTES),
                    'verifiedEvidence' => self::d($c, Sheet::VERIFIED_EVIDENCE), 'myComments' => self::d($c, Sheet::MY_COMMENTS),
                    'ticked' => (bool) $v, 'verifyRow' => $v['row'] ?? null, 'verifyRows' => $v['rows'] ?? 0,
                    'hasDecisionMaker' => $v['hasDM'] ?? false, 'untickLocked' => $v['locked'] ?? false,
                    'rejected' => $rej ? ['date' => $rej['date'], 'user' => $rej['user']] : null,
                ];
            }
        }
        return ['dates' => $dates, 'latestDate' => $latest, 'selectedDate' => $selected, 'rows' => $rows,
            'counts' => ['signals' => $total, 'ticked' => $ticked, 'rejected' => $rejectedCount]];
    }

    /** Decision Makers tab: header check result. */
    private static function dmHeaderProblem(array $dmRows): ?string
    {
        $header = null;
        foreach ($dmRows as $r) if ($r['row'] === 1) $header = $r['cells'];
        foreach (Sheet::DM_TAB_HEADERS as $i => $expected) {
            $got = trim(Sheet::display($header[$i] ?? null));
            if ($got !== $expected) {
                $col = A1::indexToCol($i);
                return "The \"Decision Makers\" tab header does not match: $col" . "1 is \"" . $got . "\", expected \"$expected\". Nothing was written. Fix the header row in Excel (the app never writes headers).";
            }
        }
        return null;
    }

    /** "job|linkedin" => Decision Makers row (sent rows). */
    private static function dmIndex(array $dmRows): array
    {
        $idx = [];
        foreach ($dmRows as $r) {
            if ($r['row'] < 2 || Sheet::isRowBlank($r['cells'])) continue;
            $job = JobUrl::normalise($r['cells'][Sheet::DMT_JOB_URL] ?? null);
            if ($job === '') continue;
            $idx[$job . '|' . LinkedIn::key($r['cells'][Sheet::DMT_LINKEDIN] ?? null)] = $r;
        }
        return $idx;
    }

    private function lastReceipt(): ?array
    {
        $r = JsonStore::read($this->receiptFile, []);
        return is_array($r) && isset($r['last']) ? $r['last'] : null;
    }

    /** True when the receipt's row is still the last row of the DM tab and unchanged. */
    private static function receiptStillValid(array $receipt, array $dmRows): bool
    {
        if (Sheet::lastValueRow($dmRows, Sheet::DM_TAB_COLS) !== $receipt['row']) return false;
        foreach ($dmRows as $r) {
            if ($r['row'] === $receipt['row']) return self::sameCells($receipt['values'], $r['cells']);
        }
        return false;
    }

    private static function sameCells(array $expected, array $actual): bool
    {
        for ($i = 0; $i < Sheet::DM_TAB_COLS; $i++) {
            $e = $expected[$i] ?? '';
            if (is_string($e) && str_starts_with($e, "'")) $e = substr($e, 1);
            if (trim(Sheet::display($e)) !== trim(Sheet::display($actual[$i] ?? ''))) return false;
        }
        return true;
    }

    /**
     * Verified page: one card per verify-tab row (several decision makers per job are separate rows),
     * grouped under their job. Status per card: waiting (M empty) / ready (M filled, not sent) / sent.
     */
    public function verifiedView(): array
    {
        $verifyRows = $this->readCached(Sheet::VERIFY);
        $dmRows = $this->readCached(Sheet::DECISION_MAKERS);
        $dmIdx = self::dmIndex($dmRows);
        $receipt = $this->lastReceipt();
        $receiptOk = $receipt && self::receiptStillValid($receipt, $dmRows);

        $groups = [];
        $status = ['waiting' => 0, 'ready' => 0, 'sent' => 0];
        foreach (self::dataRows($verifyRows) as $r) {
            $c = $r['cells'];
            $k = self::key($r);
            $gk = $k !== '' ? $k : 'row:' . $r['row'];
            $m = $c[Sheet::DM_LINKEDIN] ?? null;
            $linkKey = LinkedIn::key($m);
            $sentRow = (!Sheet::isBlank($m) && $k !== '') ? ($dmIdx[$k . '|' . $linkKey] ?? null) : null;
            $st = Sheet::isBlank($m) ? 'waiting' : ($sentRow ? 'sent' : 'ready');
            $status[$st]++;
            if (!isset($groups[$gk])) {
                $groups[$gk] = [
                    'key' => $k, 'jobUrl' => trim(self::d($c, Sheet::JOB_URL)), 'firstRow' => $r['row'],
                    'dateFound' => ExcelDate::cellToIso($c[0]) ?? self::d($c, 0),
                    'company' => self::d($c, Sheet::COMPANY), 'jobTitle' => self::d($c, Sheet::JOB_TITLE),
                    'roleGroup' => self::d($c, Sheet::ROLE_GROUP), 'roleSubGroup' => self::d($c, Sheet::ROLE_SUB_GROUP),
                    'companyType' => self::d($c, Sheet::COMPANY_TYPE), 'location' => self::d($c, Sheet::LOCATION),
                    'priorOutreach' => self::d($c, Sheet::PRIOR_OUTREACH), 'notes' => self::d($c, Sheet::NOTES),
                    'verifiedEvidence' => self::d($c, Sheet::VERIFIED_EVIDENCE), 'myComments' => self::d($c, Sheet::MY_COMMENTS),
                    'cards' => [], 'sent' => 0,
                ];
            }
            if ($st === 'sent') $groups[$gk]['sent']++;
            $groups[$gk]['cards'][] = [
                'row' => $r['row'], 'jobUrl' => trim(self::d($c, Sheet::JOB_URL)),
                'decisionMakers' => LinkedIn::splitCell($m), 'dmNote' => self::d($c, Sheet::DM_NOTE),
                'dmName' => self::d($c, Sheet::DM_NAME), 'dmTitle' => self::d($c, Sheet::DM_TITLE),
                'status' => $st,
                'sentDate' => $sentRow ? ExcelDate::cellToIso($sentRow['cells'][Sheet::DMT_DATE_VERIFIED] ?? null) : null,
                'canUndo' => $st === 'sent' && $receiptOk && $receipt['job'] === $k && $receipt['link'] === $linkKey,
            ];
        }
        $jobs = array_values($groups);
        foreach ($jobs as &$j) $j['total'] = count($j['cards']);
        unset($j);
        usort($jobs, fn ($a, $b) => $a['dateFound'] === $b['dateFound'] ? $b['firstRow'] <=> $a['firstRow'] : strcmp($b['dateFound'], $a['dateFound']));
        $warnings = [];
        if (!Sheet::hasDmDetails($verifyRows)) $warnings[] = 'The verify tab\'s S1:U1 headers are not "Decision maker note", "Decision Maker Name", "Decision Maker Title", so those columns are not shown.';
        if ($p = self::dmHeaderProblem($dmRows)) $warnings[] = $p;
        return ['jobs' => $jobs, 'counts' => $status, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ writes

    private function locked(callable $fn): mixed
    {
        return JsonStore::locked($this->lockFile, fn () => $this->wb->withSession($fn));
    }

    /** Strings Excel would treat as a formula are stored as text (leading apostrophe, which Excel hides). */
    private static function protectText(mixed $c): mixed
    {
        return (is_string($c) && preg_match('/^[=+\-@]/', $c)) ? "'" . $c : $c;
    }

    /** @return array{0:array,1:bool} values to append (A:R or A:Q) and whether column A is a date serial */
    public static function appendValues(array $cells, ?string $blockDate, bool $withSubGroup): array
    {
        $width = $withSubGroup ? Sheet::SIGNALS_COLS : Sheet::BASE_COLS;
        $vals = [];
        for ($i = 0; $i < $width; $i++) $vals[] = $cells[$i] ?? '';
        $vals = array_map(fn ($v) => $v ?? '', $vals);
        $isSerial = false;
        $a = $vals[0];
        if (is_int($a) || is_float($a)) {
            $isSerial = true;
        } else {
            $iso = ExcelDate::cellToIso($a) ?? (Sheet::isBlank($a) ? $blockDate : null);
            $serial = $iso ? ExcelDate::isoToSerial($iso) : null;
            if ($serial !== null) {
                $vals[0] = $serial;
                $isSerial = true;
            }
        }
        foreach ($vals as $i => $v) if (!($i === 0 && $isSerial)) $vals[$i] = self::protectText($v);
        return [$vals, $isSerial];
    }

    /** Stage 1: Verify (tick). */
    public function tick(mixed $jobUrl, string $user): array
    {
        $key = self::requireKey($jobUrl);
        $res = $this->locked(function (string $sid) use ($key, $user) {
            $verifyRows = $this->readFresh(Sheet::VERIFY, $sid);
            $existing = self::matches($verifyRows, $key);
            if ($existing) {
                Audit::write($user, 'tick_skip_duplicate', $key, Sheet::VERIFY, $existing[0]['row'], 'ok');
                return ['status' => 'already', 'row' => $existing[0]['row'], 'hasDecisionMaker' => !Sheet::isBlank($existing[0]['cells'][Sheet::DM_LINKEDIN] ?? null)];
            }
            $source = $this->findSignal($key);
            if (!$source) throw new NotFoundError('That Job URL is not in the Signals tab');

            $withSub = Sheet::hasSubGroup($verifyRows);
            if (!$withSub) Audit::event('warning', ['message' => 'Verify tab header R1 is not "Role Sub-Group": appending A:Q only (Role Sub-Group not copied)', 'jobUrl' => $key]);
            [$values, $isSerial] = self::appendValues($source['cells'], $source['date'], $withSub);
            $lastCol = $withSub ? 'R' : 'Q';
            $target = Sheet::lastValueRow($verifyRows) + 1;
            // Append only: the whole target row (A:U) must be empty right now, or we stop rather than overwrite.
            $current = $this->wb->range(Sheet::VERIFY, "A$target:U$target", $sid)[0] ?? [];
            if (!Sheet::isRowBlank($current)) throw new ConflictError("Row $target of the verify tab is not empty. Nothing was written. Please tidy it in Excel and try again.");

            $this->wb->patchValues(Sheet::VERIFY, "A$target:$lastCol$target", [$values], $sid);
            if ($isSerial) $this->wb->patchNumberFormat(Sheet::VERIFY, "A$target", [['yyyy-mm-dd']], $sid);
            $this->invalidate(Sheet::VERIFY);
            $check = $this->wb->range(Sheet::VERIFY, "L$target", $sid);
            if (JobUrl::normalise($check[0][0] ?? null) !== $key) {
                Audit::write($user, 'tick_append', $key, Sheet::VERIFY, $target, 'error', 'read-back mismatch');
                throw new UpstreamError('The row was written but could not be confirmed. Please reload and check.');
            }
            Audit::write($user, 'tick_append', $key, Sheet::VERIFY, $target, 'ok', $withSub ? 'A:R' : 'A:Q only (verify tab R1 is not "Role Sub-Group")');
            return ['status' => 'appended', 'row' => $target, 'hasDecisionMaker' => false];
        });
        $this->rejections->remove($key); // verifying a signal un-rejects it
        return $res;
    }

    /** Find a signal row (latest occurrence) by key, with its block date. */
    private function findSignal(string $key): ?array
    {
        foreach ([false, true] as $fresh) {
            $rows = $fresh ? $this->readFresh(Sheet::SIGNALS) : $this->readCached(Sheet::SIGNALS);
            $found = null;
            foreach (Sheet::blocks($rows) as $b) foreach ($b['rows'] as $r) {
                if (self::key($r) === $key) $found = ['cells' => $r['cells'], 'date' => $b['date']];
            }
            if ($found) return $found;
        }
        return null;
    }

    /**
     * Stage 1: Untick. Allowed only when exactly ONE verify row exists for the job and its M, S, T and U
     * are all empty. Otherwise: "Has decision makers, edit in Excel".
     */
    public function untick(mixed $jobUrl, string $user): array
    {
        $key = self::requireKey($jobUrl);
        return $this->locked(function (string $sid) use ($key, $user) {
            $verifyRows = $this->readFresh(Sheet::VERIFY, $sid);
            $m = self::matches($verifyRows, $key);
            if (!$m) return ['status' => 'not_ticked', 'row' => null];
            if (count($m) > 1) {
                $list = implode(', ', array_map(fn ($r) => $r['row'], $m));
                Audit::write($user, 'untick_refused', $key, Sheet::VERIFY, null, 'refused', "several decision-maker rows: $list");
                throw new ConflictError("Has decision makers, edit in Excel (verify tab rows $list).", 'HAS_DECISION_MAKER');
            }
            $row = $m[0]['row'];
            $withSub = Sheet::hasSubGroup($verifyRows);
            $withDm = Sheet::hasDmDetails($verifyRows);
            // Raw re-read of A:U (no header filtering): it must still be this job, with M, S, T and U empty.
            $raw = $this->wb->range(Sheet::VERIFY, "A$row:U$row", $sid)[0] ?? [];
            if (JobUrl::normalise($raw[Sheet::JOB_URL] ?? null) !== $key) throw new ConflictError('The sheet changed, please refresh.', 'ROW_CHANGED');
            $filled = self::mstuFilled($raw);
            if ($filled) {
                Audit::write($user, 'untick_refused', $key, Sheet::VERIFY, $row, 'refused', 'decision-maker columns filled: ' . implode(',', $filled));
                throw new ConflictError('Has decision makers, edit in Excel (verify tab row ' . $row . ', column' . (count($filled) > 1 ? 's ' : ' ') . implode(', ', $filled) . ').', 'HAS_DECISION_MAKER');
            }
            // Remove the whole row width that belongs to the row, so S:U of later rows stay aligned.
            $deleteTo = $withDm ? 'U' : ($withSub ? 'R' : 'Q');
            if (!$withSub && $deleteTo === 'U' && !Sheet::isBlank($raw[Sheet::ROLE_SUB_GROUP] ?? null)) {
                throw new ConflictError("Can't untick: row $row has unexpected content in column R. Please tidy it in Excel.");
            }
            $this->wb->clearContents(Sheet::VERIFY, "A$row:" . ($withSub ? 'R' : 'Q') . $row, $sid);
            $this->wb->deleteShiftUp(Sheet::VERIFY, "A$row:$deleteTo$row", $sid);
            $this->invalidate(Sheet::VERIFY);
            Audit::write($user, 'untick_remove', $key, Sheet::VERIFY, $row, 'ok', "A:$deleteTo");
            return ['status' => 'removed', 'row' => $row];
        });
    }

    /** Stage 1: Reject (app-side only; never written to the workbook). */
    public function reject(mixed $jobUrl, string $user): array
    {
        $key = self::requireKey($jobUrl);
        if (!$this->findSignal($key)) throw new NotFoundError('That Job URL is not in the Signals tab');
        $verify = self::matches($this->readCached(Sheet::VERIFY), $key);
        if ($verify) throw new ConflictError('This signal is already in the verify tab. Untick it first if you want to reject it.');
        $entry = $this->rejections->add($key, is_string($jobUrl) ? trim($jobUrl) : $key, $user);
        Audit::event('reject', ['user' => $user, 'jobUrl' => $key]);
        return ['status' => 'rejected', 'rejected' => ['date' => $entry['date'], 'user' => $entry['user']]];
    }

    public function unreject(mixed $jobUrl, string $user): array
    {
        $key = self::requireKey($jobUrl);
        $was = $this->rejections->remove($key);
        Audit::event('unreject', ['user' => $user, 'jobUrl' => $key]);
        return ['status' => $was ? 'restored' : 'not_rejected'];
    }

    // ------------------------------------------------------------------ identified verify rows (stage 2/3)

    private static function requireRow(mixed $row): int
    {
        if (is_string($row) && ctype_digit($row)) $row = (int) $row;
        if (!is_int($row) || $row < 2 || $row > 1_000_000) throw new ValidationError('A verify-tab row number is required');
        return $row;
    }

    /**
     * The verify row the browser showed, re-read right now. Refuses when that row no longer holds the same
     * Job URL (rows moved): "The sheet changed, please refresh". Returns the header-filtered row.
     */
    private function identifiedRow(array $verifyRows, int $row, string $key): array
    {
        foreach ($verifyRows as $r) {
            if ($r['row'] === $row) {
                if (self::key($r) !== $key || !Sheet::isDataRow($r['cells'])) break;
                return $r;
            }
        }
        throw new ConflictError('The sheet changed, please refresh.', 'ROW_CHANGED');
    }

    /** Last check immediately before a write: column L of $row must still be this job. */
    private function confirmRow(string $sid, int $row, string $key): void
    {
        $l = $this->wb->range(Sheet::VERIFY, "L$row", $sid);
        if (JobUrl::normalise($l[0][0] ?? null) !== $key) throw new ConflictError('The sheet changed, please refresh.', 'ROW_CHANGED');
    }

    /** Another verify row of the same job already holding this person? Returns its row number. */
    private static function personRow(array $verifyRows, string $key, string $linkKey, ?int $exceptRow = null): ?int
    {
        if ($linkKey === '') return null;
        foreach (self::matches($verifyRows, $key) as $r) {
            if ($r['row'] !== $exceptRow && LinkedIn::key($r['cells'][Sheet::DM_LINKEDIN] ?? null) === $linkKey) return $r['row'];
        }
        return null;
    }

    /** Stage 2: write column M (Decision Maker LinkedIn) of ONE identified row. Never S, T or U. */
    public function saveLinkedIn(mixed $row, mixed $jobUrl, mixed $links, string $user): array
    {
        $row = self::requireRow($row);
        $key = self::requireKey($jobUrl);
        if (!is_array($links) || array_filter($links, fn ($l) => !is_string($l))) throw new ValidationError('links must be a list of strings');
        $v = LinkedIn::validateList($links);
        foreach ($v['errors'] as $e) if ($e) throw new ValidationError($e);
        if ($v['tooMany']) throw new ValidationError('At most ' . LinkedIn::MAX_LINKS . ' links per row');
        $value = implode("\n", $v['links']);
        $action = $v['links'] ? 'write_decision_maker' : 'clear_decision_maker';
        return $this->locked(function (string $sid) use ($row, $key, $value, $user, $action, $v) {
            $verifyRows = $this->readFresh(Sheet::VERIFY, $sid);
            $this->identifiedRow($verifyRows, $row, $key);
            $other = self::personRow($verifyRows, $key, LinkedIn::key($value), $row);
            if ($other !== null) throw new ConflictError("That decision maker is already on verify row $other for this job.", 'DUPLICATE_PERSON');
            $this->confirmRow($sid, $row, $key);
            $this->wb->patchValues(Sheet::VERIFY, "M$row", [[$value]], $sid);
            $this->invalidate(Sheet::VERIFY);
            $back = $this->wb->range(Sheet::VERIFY, "L$row:M$row", $sid)[0] ?? [];
            $saved = str_replace(["\r\n", "\r"], "\n", Sheet::display($back[1] ?? ''));
            if (JobUrl::normalise($back[0] ?? null) !== $key || $saved !== $value) {
                Audit::write($user, $action, $key, Sheet::VERIFY, $row, 'error', 'read-back mismatch');
                throw new UpstreamError('Saved, but the read-back did not match. Please reload and check the row.');
            }
            Audit::write($user, $action, $key, Sheet::VERIFY, $row, 'ok', count($v['links']) . ' link(s)');
            return ['row' => $row, 'links' => LinkedIn::splitCell($saved), 'value' => $saved];
        });
    }

    /**
     * Stage 2: "+ Add another decision maker". Appends a NEW verify row: A:R copied from the job's first
     * verify row (A:Q when R1 isn't "Role Sub-Group"), the new link in M, N:Q empty, S/T/U never written.
     */
    public function addDecisionMaker(mixed $row, mixed $jobUrl, mixed $link, string $user): array
    {
        $row = self::requireRow($row);
        $key = self::requireKey($jobUrl);
        $r = LinkedIn::normalise(is_string($link) ? $link : '');
        if (!$r['ok']) throw new ValidationError($r['error']);
        $url = $r['url'];
        return $this->locked(function (string $sid) use ($row, $key, $url, $user) {
            $verifyRows = $this->readFresh(Sheet::VERIFY, $sid);
            $source = $this->identifiedRow($verifyRows, $row, $key);
            $jobRows = self::matches($verifyRows, $key);
            if ($jobRows[0]['row'] !== $row) throw new ConflictError('The sheet changed, please refresh.', 'ROW_CHANGED'); // must be the job's FIRST row
            $dup = self::personRow($verifyRows, $key, LinkedIn::key($url));
            if ($dup !== null) {
                Audit::write($user, 'add_decision_maker_refused', $key, Sheet::VERIFY, $dup, 'refused', 'duplicate job + LinkedIn');
                throw new ConflictError("That decision maker is already in the verify tab for this job (row $dup).", 'DUPLICATE_PERSON');
            }

            $withSub = Sheet::hasSubGroup($verifyRows);
            [$values, $isSerial] = self::appendValues($source['cells'], null, $withSub);
            $values[Sheet::DM_LINKEDIN] = $url;
            foreach ([Sheet::PRIOR_OUTREACH, Sheet::NOTES, Sheet::VERIFIED_EVIDENCE, Sheet::MY_COMMENTS] as $col) $values[$col] = ''; // N:Q empty
            $lastCol = $withSub ? 'R' : 'Q';
            $target = Sheet::lastValueRow($verifyRows) + 1;
            $current = $this->wb->range(Sheet::VERIFY, "A$target:U$target", $sid)[0] ?? [];
            if (!Sheet::isRowBlank($current)) throw new ConflictError("Row $target of the verify tab is not empty. Nothing was written. Please tidy it in Excel and try again.");
            $this->confirmRow($sid, $row, $key);

            $this->wb->patchValues(Sheet::VERIFY, "A$target:$lastCol$target", [$values], $sid);
            if ($isSerial) $this->wb->patchNumberFormat(Sheet::VERIFY, "A$target", [['yyyy-mm-dd']], $sid);
            $this->invalidate(Sheet::VERIFY);
            $back = $this->wb->range(Sheet::VERIFY, "L$target:M$target", $sid)[0] ?? [];
            if (JobUrl::normalise($back[0] ?? null) !== $key || LinkedIn::key($back[1] ?? null) !== LinkedIn::key($url)) {
                Audit::write($user, 'add_decision_maker', $key, Sheet::VERIFY, $target, 'error', 'read-back mismatch');
                throw new UpstreamError('The row was written but could not be confirmed. Please reload and check.');
            }
            Audit::write($user, 'add_decision_maker', $key, Sheet::VERIFY, $target, 'ok', ($withSub ? 'A:R' : 'A:Q') . " copied from row $row");
            return ['status' => 'appended', 'row' => $target, 'link' => $url];
        });
    }

    /** Stage 3: Approve ONE decision maker (one verify row) -> append ONE row to "Decision Makers". */
    public function approve(mixed $row, mixed $jobUrl, array $user): array
    {
        $row = self::requireRow($row);
        $key = self::requireKey($jobUrl);
        return $this->locked(function (string $sid) use ($row, $key, $user) {
            $src = $this->identifiedRow($this->readFresh(Sheet::VERIFY, $sid), $row, $key);
            $c = $src['cells'];
            if (Sheet::isBlank($c[Sheet::DM_LINKEDIN] ?? null)) throw new ValidationError('Add the decision maker\'s LinkedIn (column M) before approving.');

            $dmRows = $this->readFresh(Sheet::DECISION_MAKERS, $sid);
            if ($p = self::dmHeaderProblem($dmRows)) {
                Audit::write($user['email'], 'approve_refused', $key, Sheet::DECISION_MAKERS, null, 'refused', 'header mismatch');
                throw new ConflictError($p, 'DM_HEADER_MISMATCH');
            }
            // Duplicate protection per Job URL + LinkedIn: the same person can't be sent twice for a job.
            $linkKey = LinkedIn::key($c[Sheet::DM_LINKEDIN]);
            $dup = self::dmIndex($dmRows)[$key . '|' . $linkKey] ?? null;
            if ($dup) {
                Audit::write($user['email'], 'approve_skip_duplicate', $key, Sheet::DECISION_MAKERS, $dup['row'], 'ok');
                return ['status' => 'already', 'row' => $dup['row'], 'sentDate' => ExcelDate::cellToIso($dup['cells'][Sheet::DMT_DATE_VERIFIED] ?? null)];
            }

            $dateFound = $c[0] ?? '';
            if (!is_int($dateFound) && !is_float($dateFound)) {
                $iso = ExcelDate::cellToIso($dateFound);
                if ($iso) $dateFound = ExcelDate::isoToSerial($iso);
            }
            $today = ExcelDate::todaySerial($this->timezone);
            $values = [
                $today, $user['name'], $dateFound, $c[Sheet::COMPANY] ?? '', $c[Sheet::DOMAIN] ?? '', $c[Sheet::JOB_TITLE] ?? '',
                $c[Sheet::ROLE_GROUP] ?? '', $c[Sheet::ROLE_SUB_GROUP] ?? '', $c[Sheet::COMPANY_TYPE] ?? '', $c[Sheet::LOCATION] ?? '',
                trim(Sheet::display($c[Sheet::JOB_URL] ?? '')), $c[Sheet::DM_LINKEDIN] ?? '', $c[Sheet::DM_NAME] ?? '', $c[Sheet::DM_TITLE] ?? '',
                'Ready to push', $row, $c[Sheet::DM_NOTE] ?? '',
            ];
            $values = array_map(fn ($v) => self::protectText($v ?? ''), $values); // numbers are left as they are

            $target = Sheet::lastValueRow($dmRows, Sheet::DM_TAB_COLS) + 1;
            $current = $this->wb->range(Sheet::DECISION_MAKERS, "A$target:Q$target", $sid)[0] ?? [];
            if (!Sheet::isRowBlank($current)) throw new ConflictError("Row $target of the Decision Makers tab is not empty. Nothing was written.");
            $this->confirmRow($sid, $row, $key);

            $this->wb->patchValues(Sheet::DECISION_MAKERS, "A$target:Q$target", [$values], $sid);
            $this->wb->patchNumberFormat(Sheet::DECISION_MAKERS, "A$target", [['yyyy-mm-dd']], $sid);
            if (is_int($dateFound) || is_float($dateFound)) $this->wb->patchNumberFormat(Sheet::DECISION_MAKERS, "C$target", [['yyyy-mm-dd']], $sid);
            $this->invalidate(Sheet::DECISION_MAKERS);

            $back = $this->wb->range(Sheet::DECISION_MAKERS, "K$target:L$target", $sid)[0] ?? [];
            if (JobUrl::normalise($back[0] ?? null) !== $key || LinkedIn::key($back[1] ?? null) !== $linkKey) {
                Audit::write($user['email'], 'approve_append', $key, Sheet::DECISION_MAKERS, $target, 'error', 'read-back mismatch');
                throw new UpstreamError('The row was written but could not be confirmed. Please reload and check the Decision Makers tab.');
            }
            JsonStore::update($this->receiptFile, [], fn () => ['last' => [
                'job' => $key, 'link' => $linkKey, 'sourceRow' => $row, 'row' => $target, 'values' => $values, 'by' => $user['email'], 'at' => gmdate('c'),
            ]]);
            Audit::write($user['email'], 'approve_append', $key, Sheet::DECISION_MAKERS, $target, 'ok', "source row $row");
            return ['status' => 'appended', 'row' => $target, 'sentDate' => ExcelDate::serialToIso($today)];
        });
    }

    /** Stage 3 undo: only the row the app just appended, only while it is still last and unchanged. */
    public function undoApprove(mixed $row, mixed $jobUrl, string $user): array
    {
        $row = self::requireRow($row);
        $key = self::requireKey($jobUrl);
        return $this->locked(function (string $sid) use ($row, $key, $user) {
            $src = $this->identifiedRow($this->readFresh(Sheet::VERIFY, $sid), $row, $key);
            $receipt = $this->lastReceipt();
            if (!$receipt || $receipt['job'] !== $key || $receipt['link'] !== LinkedIn::key($src['cells'][Sheet::DM_LINKEDIN] ?? null)) {
                throw new ConflictError('Undo is only possible for the decision maker the app added most recently.', 'UNDO_NOT_ALLOWED');
            }
            $dmRows = $this->readFresh(Sheet::DECISION_MAKERS, $sid);
            if (!self::receiptStillValid($receipt, $dmRows)) {
                Audit::write($user, 'approve_undo_refused', $key, Sheet::DECISION_MAKERS, $receipt['row'], 'refused', 'not last or changed');
                throw new ConflictError('Can\'t undo: that row is no longer the last row of the Decision Makers tab, or it has been changed.', 'UNDO_NOT_ALLOWED');
            }
            $dmRow = $receipt['row'];
            $this->wb->clearContents(Sheet::DECISION_MAKERS, "A$dmRow:Q$dmRow", $sid);
            $this->invalidate(Sheet::DECISION_MAKERS);
            JsonStore::update($this->receiptFile, [], fn () => []);
            Audit::write($user, 'approve_undo', $key, Sheet::DECISION_MAKERS, $dmRow, 'ok', "source row $row");
            return ['status' => 'undone', 'row' => $dmRow];
        });
    }
}
