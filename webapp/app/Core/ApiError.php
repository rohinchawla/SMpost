<?php declare(strict_types=1);
if (!defined('GO_BOOT')) { http_response_code(404); exit; }

/**
 * Every failure the API reports.
 *
 * @mock mock-api/server.mjs:96-104 (class ApiError)
 *
 * `retryable` is the one flag every agent branches on: true means back off and
 * try again, false means stop and do not reword the request to get around it.
 * The rule is copied exactly from the mock, not re-derived.
 */
final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    /** @mock server.mjs:99 */
    public function retryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function validation(string $message, array $details = []): self
    {
        return new self(422, 'VALIDATION_FAILED', $message, $details);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'VALIDATION_FAILED', $message);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self(409, $code, $message, $details);
    }

    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self(422, $code, $message, $details);
    }

    public static function unauthorized(string $message): self
    {
        return new self(401, 'UNAUTHORIZED', $message);
    }

    public static function forbidden(string $message, array $details = []): self
    {
        return new self(403, 'FORBIDDEN_SCOPE', $message, $details);
    }
}
