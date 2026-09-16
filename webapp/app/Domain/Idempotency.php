<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Replay protection for agent writes.
 *
 * @mock mock-api/server.mjs:163-196
 *
 *   key_hash     = sha256(agent_code + ':' + <Idempotency-Key header>)
 *   request_hash = sha256(JSON.stringify(parsedBody ?? {}))
 *
 * The key is namespaced by agent, so two agents may use the same raw key
 * without colliding. The request hash is over the re-serialised body, not the
 * raw bytes, so whitespace differences between two otherwise identical requests
 * do not produce a false conflict.
 *
 * Where this differs from the mock, deliberately: the mock does a read then a
 * write, which is a race. Here the INSERT itself is the lock, so two concurrent
 * requests carrying the same key cannot both run the handler.
 */
final class Idempotency
{
    private const TTL_HOURS = 72;

    public function __construct(
        public readonly ?string $keyHash,
        public readonly ?string $requestHash,
        public readonly bool $replay = false,
        public readonly int $status = 0,
        public readonly mixed $body = null,
    ) {}

    /** No Idempotency-Key header means the feature is simply off for this call. */
    public static function begin(?string $header, string $agent, array $body, string $method, string $path): self
    {
        if ($header === null || $header === '') return new self(null, null);

        $keyHash = hash('sha256', $agent . ':' . $header);
        $reqHash = hash('sha256', Canon::encode($body ?: []));

        try {
            $inserted = Db::exec(
                'INSERT IGNORE INTO idempotency_keys
                   (key_hash, agent_code, method, path, request_hash, response_status, response_body, expires_at)
                 VALUES (?,?,?,?,?,0,?,?)',
                [$keyHash, $agent, $method, $path, $reqHash, 'null',
                 Dt::plusSecondsDb(self::TTL_HOURS * 3600)]
            );
        } catch (PDOException $e) {
            if (!Db::isDuplicate($e)) throw $e;
            $inserted = 0;
        }

        if ($inserted === 1) return new self($keyHash, $reqHash);

        $existing = Db::row('SELECT request_hash, response_status, response_body FROM idempotency_keys WHERE key_hash = ?', [$keyHash]);
        if ($existing === null) return new self($keyHash, $reqHash);

        if (!hash_equals((string) $existing['request_hash'], $reqHash)) {
            throw ApiError::conflict('IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_BODY',
                'this Idempotency-Key was already used with a different body');
        }

        if ((int) $existing['response_status'] === 0) {
            // A concurrent request holding the same key has not finished yet.
            // Retryable, so the agent's own backoff picks up the replay shortly.
            throw new ApiError(503, 'SERVICE_UNAVAILABLE',
                'a request with this Idempotency-Key is still in flight');
        }

        return new self($keyHash, $reqHash, true,
            (int) $existing['response_status'], Canon::decode((string) $existing['response_body']));
    }

    public function active(): bool
    {
        return $this->keyHash !== null && !$this->replay;
    }

    /** Store the outcome so a later retry replays it verbatim. */
    public function complete(int $status, mixed $payload): void
    {
        if (!$this->active()) return;
        Db::exec('UPDATE idempotency_keys SET response_status = ?, response_body = ? WHERE key_hash = ?',
            [$status, Canon::encode($payload), $this->keyHash]);
    }

    /**
     * Drop the reservation.
     *
     * Used when the handler threw, and when the response is a 204. Both match
     * the mock, where an exception skips the store entirely and the 204 branch
     * returns before reaching it - so in both cases a retry re-runs the handler
     * rather than replaying a failure.
     */
    public function abandon(): void
    {
        if (!$this->active()) return;
        try { Db::exec('DELETE FROM idempotency_keys WHERE key_hash = ? AND response_status = 0', [$this->keyHash]); }
        catch (Throwable) { /* best effort */ }
    }
}
