<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The job log.
 *
 * @mock mock-api/server.mjs:219-322
 *
 * Every run writes a row, including a run that did nothing, because absence has
 * to be an event. A pipeline that quietly stops is the failure nobody notices
 * for five weeks.
 */
final class Runs
{
    /** @mock server.mjs:225-241 (createRun) */
    public static function createRun(array $ctx): array
    {
        $b = $ctx['body'];
        foreach (['run_uid', 'agent_code', 'business_date_ist'] as $f) {
            if (empty($b[$f])) {
                throw new ApiError(400, 'VALIDATION_FAILED', "{$f} is required", ['field' => $f]);
            }
        }
        if ($b['agent_code'] !== $ctx['agent']) {
            throw ApiError::forbidden(
                "key belongs to {$ctx['agent']}, cannot open a run for {$b['agent_code']}");
        }

        $existing = Db::row('SELECT * FROM agent_runs WHERE run_uid = ?', [$b['run_uid']]);
        if ($existing !== null) {
            return [200, ['data' => Views::run($existing), 'replayed' => true]];
        }

        $attempt = (int) ($b['attempt'] ?? 1);
        try {
            Db::exec(
                'INSERT INTO agent_runs (run_uid, agent_code, agent_version, trigger_type,
                                         business_date_ist, attempt, dry_run, started_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$b['run_uid'], $b['agent_code'], $b['agent_version'] ?? 'v1',
                 $b['trigger_type'] ?? 'schedule', $b['business_date_ist'], $attempt,
                 !empty($b['dry_run']) ? 1 : 0, Dt::nowDb()]
            );
        } catch (PDOException $e) {
            if (!Db::isDuplicate($e)) throw $e;
            // The unique index is the arbiter, not a prior read: two crons firing
            // at exactly 05:00:00.000 both pass a read-based check.
            $clash = Db::row('SELECT run_uid, status FROM agent_runs
                               WHERE agent_code = ? AND business_date_ist = ? AND attempt = ?',
                [$b['agent_code'], $b['business_date_ist'], $attempt]);
            if ($clash !== null) {
                throw ApiError::conflict('DUPLICATE_RUN_SLOT',
                    "{$b['agent_code']} already has a run for {$b['business_date_ist']} attempt {$attempt}",
                    ['existing_run_uid' => $clash['run_uid'], 'existing_status' => $clash['status']]);
            }
            $again = Db::row('SELECT * FROM agent_runs WHERE run_uid = ?', [$b['run_uid']]);
            if ($again !== null) return [200, ['data' => Views::run($again), 'replayed' => true]];
            throw $e;
        }

        $row = Db::row('SELECT * FROM agent_runs WHERE run_uid = ?', [$b['run_uid']]);
        return [201, ['data' => Views::run($row) + ['config' => Settings::configPayload()]]];
    }

    /** @mock server.mjs:251-268 (patchRun) */
    public static function patchRun(array $ctx): array
    {
        $b = $ctx['body'];
        $r = Views::requireRun($ctx['params']['runUid'] ?? null);

        $finished = in_array($b['status'] ?? '', ['succeeded', 'partial', 'failed', 'skipped', 'timed_out'], true);
        $now = Dt::nowDb();

        // ?? not isset: a client sending an explicit null keeps the stored value,
        // which is what the mock's `body.x ?? r.x` does.
        Db::exec(
            'UPDATE agent_runs SET status=?, skip_reason=?, items_in=?, items_ok=?, items_failed=?,
                    items_skipped=?, error_code=?, error_message=?, metrics_json=?,
                    heartbeat_at=?, finished_at=?, duration_ms=? WHERE run_uid=?',
            [
                $b['status'] ?? $r['status'],
                $b['skip_reason'] ?? $r['skip_reason'],
                (int) ($b['items_in'] ?? $r['items_in']),
                (int) ($b['items_ok'] ?? $r['items_ok']),
                (int) ($b['items_failed'] ?? $r['items_failed']),
                (int) ($b['items_skipped'] ?? $r['items_skipped']),
                $b['error_code'] ?? $r['error_code'],
                $b['error_message'] ?? $r['error_message'],
                isset($b['metrics_json']) ? Canon::encode($b['metrics_json']) : $r['metrics_json'],
                $now,
                $finished ? $now : $r['finished_at'],
                $finished ? Dt::diffMs((string) $r['started_at'], $now) : $r['duration_ms'],
                $r['run_uid'],
            ]
        );

        return [200, ['data' => Views::run(Db::row('SELECT * FROM agent_runs WHERE run_uid = ?', [$r['run_uid']]))]];
    }

    /** @mock server.mjs:270-284 (postEvents) */
    public static function postEvents(array $ctx): array
    {
        $b = $ctx['body'];
        $r = Views::requireRun($ctx['params']['runUid'] ?? null);
        $events = isset($b['events']) && is_array($b['events']) ? $b['events'] : [$b];

        $seq = (int) Db::one('SELECT COALESCE(MAX(seq),0) FROM run_events WHERE run_id = ?', [(int) $r['id']]);
        foreach ($events as $e) {
            $seq++;
            Db::exec(
                'INSERT INTO run_events (run_id, seq, level, event_code, entity_type, entity_uid, message, data_json, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [(int) $r['id'], $seq, $e['level'] ?? 'info', $e['event_code'] ?? 'note',
                 $e['entity_type'] ?? 'none', $e['entity_uid'] ?? null, $e['message'] ?? null,
                 isset($e['data']) ? Canon::encode($e['data']) : null, Dt::nowDb()]
            );
        }
        return [201, ['data' => ['accepted' => count($events), 'last_seq' => $seq]]];
    }

    /**
     * @mock server.mjs:286-303 (deadLetter)
     *
     * Repeat failures deduplicate into one row with a rising attempts count, so
     * six bad days appear as one item on the ops list rather than six.
     */
    public static function deadLetter(array $ctx): array
    {
        $b = $ctx['body'];
        $agent = $ctx['agent'];

        return Db::tx(static function () use ($b, $agent) {
            // <=> is MySQL's null-safe equality. SQLite's `IS ?` has no direct
            // equivalent in a prepared statement, and uq_dl_dedupe carries a
            // nullable entity_uid.
            $existing = Db::row(
                'SELECT * FROM dead_letters
                  WHERE agent_code = ? AND stage = ? AND error_code = ? AND entity_uid <=> ?
                  FOR UPDATE',
                [$agent, $b['stage'] ?? '', $b['error_code'] ?? '', $b['entity_uid'] ?? null]
            );

            if ($existing !== null) {
                Db::exec('UPDATE dead_letters SET attempts = attempts + 1, last_seen_at = ? WHERE id = ?',
                    [Dt::nowDb(), (int) $existing['id']]);
                return [200, ['data' => [
                    'dl_uid'   => $existing['dl_uid'],
                    'attempts' => (int) $existing['attempts'] + 1,
                    'deduped'  => true,
                ]]];
            }

            $uid = $b['dl_uid'] ?? Uuid::v4();
            Db::exec(
                'INSERT INTO dead_letters (dl_uid, agent_code, entity_type, entity_uid, stage, error_code, error_message, payload_json)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$uid, $agent, $b['entity_type'] ?? 'none', $b['entity_uid'] ?? null,
                 $b['stage'] ?? '', $b['error_code'] ?? '', $b['error_message'] ?? '',
                 Canon::encode($b['payload'] ?? [])]
            );
            return [201, ['data' => ['dl_uid' => $uid, 'attempts' => 1, 'deduped' => false]]];
        });
    }
}
