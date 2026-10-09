<?php

namespace justinholtweb\vismaz\errors;

use yii\base\Exception;

/**
 * A request to Visma that did not succeed.
 *
 * Carries the HTTP status, because the caller has to tell "Visma said no" (a 4xx: the request is
 * wrong, and sending it again gets the same answer) from "Visma could not be asked" (no status, or
 * a 5xx: worth retrying later).
 */
class ApiException extends Exception
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether trying again later could succeed. A 429 has already been waited out by
     * `Api::request()` before it gets here, so one that still arrives is treated as transient too.
     */
    public function isTransient(): bool
    {
        return $this->statusCode === null || $this->statusCode === 429 || $this->statusCode >= 500;
    }

    public function getName(): string
    {
        return 'Visma API error';
    }
}
