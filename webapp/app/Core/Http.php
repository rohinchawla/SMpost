<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Everything that writes to the response. One place, so the envelope cannot
 * drift and so no stray byte can ever precede a header.
 *
 * @mock mock-api/server.mjs:109-146
 */
final class Http
{
    private static bool $sent = false;

    /** Discard any buffered output before writing headers. */
    private static function clearBuffers(): void
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
    }

    /** @mock server.mjs:110-121 (sendJson) */
    public static function json(int $status, mixed $payload, array $headers = []): void
    {
        if (self::$sent) return;
        self::$sent = true;
        $body = json_encode($payload, JSON_PRETTY_PRINT | Canon::FLAGS);
        self::clearBuffers();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        foreach ($headers as $k => $v) { header($k . ': ' . $v); }
        echo $body;
    }

    /**
     * 204 with no body, no Content-Type and no idempotency record.
     * PHP adds text/html by default; the mock sends nothing at all.
     * @mock server.mjs:1105
     */
    public static function noContent(): void
    {
        if (self::$sent) return;
        self::$sent = true;
        self::clearBuffers();
        http_response_code(204);
        header_remove('Content-Type');
        header_remove('Content-Length');
    }

    /** @mock server.mjs:123-135 (sendError) */
    public static function error(Throwable $e): void
    {
        $err = $e instanceof ApiError ? $e : new ApiError(500, 'INTERNAL', 'internal error');

        if (!$e instanceof ApiError) {
            error_log('[go] unhandled: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }

        self::json($err->status, [
            'error' => [
                'code'      => $err->errorCode,
                'message'   => $err->getMessage(),
                'retryable' => $err->retryable(),
                // (object) so an empty details serialises as {} and not [].
                // An agent reading error.details.topic_count depends on it.
                'details'   => (object) $err->details,
            ],
            'request_id'  => 'req_' . substr(bin2hex(random_bytes(8)), 0, 12),
            'server_time' => Dt::nowIso(),
        ]);
    }

    /** 207, spelled out because not every SAPI knows the reason phrase. */
    public static function multiStatus(mixed $payload): void
    {
        self::json(207, $payload);
    }

    public static function html(int $status, string $body, array $headers = []): void
    {
        if (self::$sent) return;
        self::$sent = true;
        self::clearBuffers();
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($headers as $k => $v) { header($k . ': ' . $v); }
        echo $body;
    }

    public static function redirect(string $to, int $status = 303): void
    {
        if (self::$sent) return;
        self::$sent = true;
        self::clearBuffers();
        http_response_code($status);
        header('Location: ' . $to);
    }

    public static function alreadySent(): bool
    {
        return self::$sent;
    }

    /**
     * The request body, parsed.
     *
     * When a request exceeds post_max_size PHP silently empties php://input but
     * leaves CONTENT_LENGTH set, so an oversized image upload would otherwise
     * look like an empty body and return a baffling 400. 413 is not retryable,
     * so A3 dead-letters instead of grinding.
     */
    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false) $raw = '';

        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($declared > 0 && $raw === '') {
            throw new ApiError(413, 'PAYLOAD_TOO_LARGE',
                'request body exceeded post_max_size', ['declared_bytes' => $declared]);
        }
        if (strlen($raw) > 32 * 1024 * 1024) {
            throw new ApiError(413, 'PAYLOAD_TOO_LARGE', 'body over 32MB');
        }
        if ($raw === '') return [];

        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiError::badRequest('body is not valid JSON');
        }
        return is_array($parsed) ? $parsed : [];
    }
}
