<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The test surface: /__mock/*.
 *
 * The contract suite makes 28 calls to these routes, because in the Node mock
 * they stand in for the human at the two approval gates. They exist here so the
 * same suite can run unmodified against the real app.
 *
 * What makes that honest rather than circular: every route below is parameter
 * marshalling over ReviewService - the same methods the real UI controllers
 * call. There is no business logic in this file. A green suite is therefore
 * evidence about the approval code path that actually ships.
 *
 * Three gates, all of which must hold:
 *   1. config env === 'test'
 *   2. config test_harness_enabled === true
 *   3. header X-Test-Token matches, compared with hash_equals
 *
 * and reset additionally refuses any database whose name does not end in
 * `_test`, so a truncating endpoint can never point at the database holding real
 * LinkedIn URNs.
 */
final class Harness
{
    public static function enabled(): bool
    {
        return go_is_test_env();
    }

    public static function handles(string $path): bool
    {
        return self::enabled() && str_starts_with($path, '/__mock/');
    }

    private static function authorise(): void
    {
        if (!self::enabled()) throw ApiError::notFound('not found');
        $sent = (string) ($_SERVER['HTTP_X_TEST_TOKEN'] ?? '');
        $want = (string) go_setting('test_token', '');
        if ($want === '' || !hash_equals($want, $sent)) {
            throw ApiError::unauthorized('bad or missing X-Test-Token');
        }
    }

    /**
     * Injected failures, so the agents' retry paths are exercised for real.
     * Runs AFTER authentication, matching the mock: a 401 beats an injected 503.
     */
    public static function applyFault(string $path): void
    {
        if (!self::enabled()) return;
        $mode = (string) ($_SERVER['HTTP_X_MOCK_FAULT'] ?? '');
        if ($mode === '') return;
        match ($mode) {
            'http_503' => throw new ApiError(503, 'SERVICE_UNAVAILABLE', 'injected fault: upstream unavailable'),
            'http_429' => throw new ApiError(429, 'RATE_LIMITED', 'injected fault: rate limited'),
            'http_500' => throw new ApiError(500, 'INTERNAL', 'injected fault: internal error'),
            default    => null,
        };
    }

    public static function dispatch(string $path): void
    {
        self::authorise();
        $body = Router::method() === 'GET' ? [] : Http::readJsonBody();
        $actor = 'owner';

        [$status, $payload] = match ($path) {
            '/__mock/reset'           => self::reset(),
            '/__mock/state'           => [200, ['data' => Dashboard::state()]],
            '/__mock/approve-topics'  => [200, ['data' => ReviewService::approveTopics(
                                            $body['batch_uid'] ?? null, (int) ($body['count'] ?? 3),
                                            $actor, !empty($body['edit_first_title']))]],
            '/__mock/approve-post'    => [200, ['data' => ReviewService::approvePost(
                                            $body['post_uid'] ?? null, (int) ($body['select_image'] ?? 1), $actor,
                                            $body['scheduled_date_ist'] ?? null,
                                            self::tagsWithReplacement($body))]],
            '/__mock/set-status'      => [200, ['data' => self::setStatus($body, $actor)]],
            '/__mock/edit-post'       => [200, ['data' => ReviewService::editPost(
                                            (string) ($body['post_uid'] ?? ''),
                                            ['final_body' => $body['final_body'] ?? null], $actor)]],
            default                   => throw ApiError::notFound("no route for {$path}"),
        };

        Http::json($status, $payload);
    }

    /** @mock server.mjs:971-993 - topic and post branches differ, deliberately. */
    private static function setStatus(array $b, string $actor): array
    {
        $entity = (string) ($b['entity'] ?? 'topic');
        $uid    = (string) ($b['uid'] ?? '');
        $status = (string) ($b['review_status'] ?? 'pending');
        return $entity === 'post'
            ? ReviewService::setPostStatus($uid, $status, $actor)
            : ReviewService::setTopicStatus($uid, $status, $actor);
    }

    /** The mock replaces the LAST hashtag, preserving the count. */
    private static function tagsWithReplacement(array $b): ?array
    {
        if (empty($b['replace_hashtag'])) return null;
        $p = Db::row('SELECT final_hashtags FROM posts WHERE post_uid = ?', [$b['post_uid'] ?? '']);
        if ($p === null) return null;
        $tags = Canon::decode((string) $p['final_hashtags'], []) ?: [];
        if ($tags === []) return [$b['replace_hashtag']];
        array_pop($tags);
        $tags[] = $b['replace_hashtag'];
        return $tags;
    }

    /**
     * Truncate the pipeline so the suite starts from an empty database.
     *
     * users and api_keys are never touched, so the six keys survive between runs.
     */
    private static function reset(): array
    {
        $dbName = (string) (go_setting('db')['name'] ?? '');
        if (!str_ends_with($dbName, '_test')) {
            throw ApiError::forbidden(
                "refusing to truncate '{$dbName}': the reset endpoint only runs against a database whose name ends in _test");
        }

        $tables = ['run_events', 'publish_attempts', 'post_metrics_daily', 'post_images', 'posts',
                   'topics', 'topic_batches', 'agent_runs', 'dead_letters', 'idempotency_keys',
                   'review_actions', 'field_edits', 'ui_sessions', 'login_attempts'];

        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) { Db::pdo()->exec("TRUNCATE TABLE {$t}"); }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS=1');

        Media::purgeAll();

        return [200, ['data' => ['reset' => true, 'database' => $dbName, 'tables' => count($tables)]]];
    }
}
