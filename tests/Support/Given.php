<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests\Support;

use Psr\Http\Message\RequestInterface;

/** A request the stand-in was given: the request, its body as it was sent, and Guzzle's options for it. */
final class Given
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly RequestInterface $request,
        public readonly string $body,
        public readonly array $options,
    ) {
    }
}
