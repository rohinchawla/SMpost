<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The response shapers.
 *
 * @mock mock-api/server.mjs:243-249 (runView)
 * @mock mock-api/server.mjs:531-534 (postStateView)
 * @mock mock-api/server.mjs:617-621 (imageView)
 *
 * Kept in one file because the exact field set of each is part of the contract:
 * an agent reads these keys by name, and a missing one is a silent break.
 */
final class Views
{
    public static function run(array $r): array
    {
        return [
            'run_uid'           => $r['run_uid'],
            'agent_code'        => $r['agent_code'],
            'status'            => $r['status'],
            'business_date_ist' => $r['business_date_ist'],
            'attempt'           => (int) $r['attempt'],
            'dry_run'           => (bool) $r['dry_run'],
            'started_at'        => Dt::iso($r['started_at'] ?? null),
            'finished_at'       => Dt::iso($r['finished_at'] ?? null),
            'items_in'          => (int) $r['items_in'],
            'items_ok'          => (int) $r['items_ok'],
            'items_failed'      => (int) $r['items_failed'],
            'items_skipped'     => (int) $r['items_skipped'],
            'skip_reason'       => $r['skip_reason'],
            'error_code'        => $r['error_code'],
        ];
    }

    public static function postState(array $p): array
    {
        return [
            'post_uid'        => $p['post_uid'],
            'lifecycle_state' => $p['lifecycle_state'],
            'review_status'   => $p['review_status'],
            'word_count'      => (int) $p['final_word_count'],
            'revision'        => (int) $p['revision'],
        ];
    }

    public static function image(array $i): array
    {
        return [
            'image_uid'      => $i['image_uid'],
            'option_index'   => (int) $i['option_index'],
            'public_url'     => $i['public_url'],
            'provider_model' => $i['provider_model'],
            'concept_label'  => $i['concept_label'],
            'alt_text'       => $i['alt_text'],
            'bytes'          => (int) $i['bytes'],
            'sha256'         => $i['sha256'],
            'status'         => $i['status'],
        ];
    }

    /** A run row by uid, or 404. Used by nearly every handler. */
    public static function requireRun(?string $runUid): array
    {
        if ($runUid === null || $runUid === '') throw ApiError::notFound('run not found');
        $r = Db::row('SELECT * FROM agent_runs WHERE run_uid = ?', [$runUid]);
        if ($r === null) throw ApiError::notFound('run not found');
        return $r;
    }
}
