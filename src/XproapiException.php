<?php

declare(strict_types=1);

namespace Xproapi;

use RuntimeException;

/**
 * Any error answer from the API ({"status":"error","error":<code>,"message":"..."}) or a network failure.
 * Catch a subclass to handle one case, or this class for all of them.
 */
class XproapiException extends RuntimeException
{
    /**
     * @param  int  $status  HTTP status (0 for network errors)
     * @param  array<string, mixed>|null  $body  the decoded JSON error body, when there was one
     */
    public function __construct(string $message, public readonly int $status = 0, public readonly ?array $body = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $status);
    }

    /**
     * The matching subclass for an HTTP status.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function fromResponse(int $status, ?array $body, ?int $retryAfter = null): self
    {
        $message = is_string($body['message'] ?? null) ? $body['message'] : "Request failed with HTTP {$status}.";

        $class = match (true) {
            $status === 400 => BadRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => InsufficientCreditsException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServiceUnavailableException::class,
            default => self::class,
        };

        return new $class($message, $status, $body, $retryAfter);
    }
}
