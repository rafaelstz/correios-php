<?php

namespace Correios\Services\Batch;

/**
 * An immutable, ready-to-execute request for the {@see Batch} executor.
 *
 * A prepared request carries the caller-supplied $key (so results can be mapped
 * back to a service/route/chunk) and a configured cURL handle. When preparation
 * fails before a handle could be built (e.g. CEP validation), $handle is null
 * and $error holds the reason — the batch turns it into a failure result entry
 * without ever touching the network.
 */
final class PreparedRequest
{
    public function __construct(
        public readonly string|int $key,
        public readonly ?\CurlHandle $handle = null,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string|int $key, string $error): self
    {
        return new self($key, null, $error);
    }
}
