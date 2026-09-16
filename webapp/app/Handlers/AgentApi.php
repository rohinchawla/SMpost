<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * The agent surface: /api/v1/agent/**.
 *
 * @mock mock-api/server.mjs:1013-1045 (the ROUTES table)
 *
 * This dispatcher never calls session_start() and never reads a cookie. The
 * disjointness from the human surface is structural rather than a flag, so
 * neither can fall through to the other.
 */
final class AgentApi
{
    private const P = '/api/v1/agent';

    /** [method, path, required scope (null = any valid key), handler] */
    public static function routes(): array
    {
        $p = self::P;
        return Router::sort([
            ['POST',  "{$p}/runs",                          'runs:write',        [Runs::class, 'createRun']],
            ['PATCH', "{$p}/runs/:runUid",                   'runs:write',        [Runs::class, 'patchRun']],
            ['POST',  "{$p}/runs/:runUid/events",            'runs:write',        [Runs::class, 'postEvents']],
            ['POST',  "{$p}/dead-letters",                   'deadletters:write', [Runs::class, 'deadLetter']],
            ['GET',   "{$p}/config",                          null,               [Meta::class, 'getConfig']],
            ['GET',   "{$p}/queue-depth",                     null,               [Meta::class, 'queueDepth']],

            ['POST',  "{$p}/topic-batches",                   'topics:write',     [Topics::class, 'createBatch']],
            ['POST',  "{$p}/topics/dedupe-check",             'topics:write',     [Topics::class, 'dedupeCheck']],
            ['POST',  "{$p}/topic-batches/:batchUid/topics",  'topics:write',     [Topics::class, 'addTopics']],
            ['POST',  "{$p}/topic-batches/:batchUid/submit",  'topics:write',     [Topics::class, 'submitBatch']],

            ['GET',   "{$p}/topics",                          'topics:read',      [Topics::class, 'listTopics']],
            ['POST',  "{$p}/topics/:topicUid/claim",          'topics:claim',     [Topics::class, 'claimTopic']],
            ['POST',  "{$p}/posts",                           'posts:write',      [Posts::class, 'createPost']],

            ['GET',   "{$p}/posts",                           'posts:read',       [Posts::class, 'listPosts']],
            ['POST',  "{$p}/posts/:postUid/images",           'images:write',     [Images::class, 'uploadImage']],
            ['POST',  "{$p}/posts/:postUid/submit-for-review",'posts:submit',     [Posts::class, 'submitForReview']],

            ['GET',   "{$p}/publish-queue/next",              'publish:write',    [Publish::class, 'publishQueueNext']],
            ['POST',  "{$p}/posts/:postUid/publish-lease",    'publish:write',    [Publish::class, 'publishLease']],
            ['POST',  "{$p}/posts/:postUid/publish-result",   'publish:write',    [Publish::class, 'publishResult']],

            ['GET',   "{$p}/posts/published",                 'posts:read',       [Metrics::class, 'publishedPosts']],
            ['POST',  "{$p}/metrics:bulk-upsert",             'metrics:write',    [Metrics::class, 'metricsUpsert']],
        ]);
    }

    public static function handles(string $path): bool
    {
        return str_starts_with($path, self::P . '/');
    }

    /**
     * Order of operations, matching the mock exactly:
     *   route match -> authenticate -> injected fault -> read body ->
     *   idempotency (a replay short-circuits) -> handler -> store -> send.
     *
     * Authentication runs before fault injection, so a 401 beats an injected 503.
     */
    public static function dispatch(string $path): void
    {
        $method = Router::method();
        $hit = Router::match(self::routes(), $method, $path);
        if ($hit === null) throw ApiError::notFound("no route for {$method} {$path}");

        [$entry, $params] = $hit;
        [, , $scope, $handler] = $entry;

        $agent = AgentAuth::authenticate($scope);
        // The suite injects 500s and timeouts here to prove the agents retry. The
        // harness file is not in a production upload, so this is conditional:
        // without it the call simply does not happen.
        if (class_exists('Harness', false)) { Harness::applyFault($path); }

        $body = in_array($method, ['POST', 'PATCH', 'PUT'], true) ? Http::readJsonBody() : [];

        $idem = Idempotency::begin(
            self::idempotencyHeader(), $agent, $body, $method, $path
        );
        if ($idem->replay) {
            Http::json($idem->status, $idem->body, ['Idempotency-Replayed' => 'true']);
            return;
        }

        try {
            [$status, $payload] = $handler([
                'agent'  => $agent,
                'body'   => $body,
                'query'  => Router::query(),
                'params' => $params,
            ]);
        } catch (Throwable $e) {
            // A failed request leaves no trace, so a retry re-runs rather than
            // replaying the failure. Matches the mock, where a throw skips the store.
            $idem->abandon();
            throw $e;
        }

        if ($status === 204) {
            $idem->abandon();
            Http::noContent();
            return;
        }

        $idem->complete($status, $payload);
        if ($status === 207) { Http::multiStatus($payload); return; }
        Http::json($status, $payload);
    }

    private static function idempotencyHeader(): ?string
    {
        $h = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        if ($h === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Idempotency-Key') === 0) { $h = $v; break; }
            }
        }
        $h = is_string($h) ? trim($h) : null;
        return ($h === null || $h === '') ? null : $h;
    }
}
