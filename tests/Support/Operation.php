<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests\Support;

use stdClass;

/** One operation of the API's document, as {@see Document} reads it. */
final class Operation
{
    /**
     * @param string $id its operationId
     * @param string $method its method, in capitals
     * @param string $path its path, as the document names it
     * @param int $success the first status of 2xx it answers
     * @param list<array{name: string, in: string, required: bool, schema: stdClass}> $parameters its
     *     path's parameters and its own, one for each place and name, its own in place of its path's
     * @param stdClass|null $body the schema of its JSON body, or null where it takes none
     * @param string $pattern what its path on the document's server matches, each {name} a group
     * @param list<string> $names the names of its path's parameters, in their order
     * @param stdClass $responses its answers, by their statuses
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $path,
        public readonly int $success,
        public readonly array $parameters,
        public readonly ?stdClass $body,
        public readonly string $pattern,
        public readonly array $names,
        public readonly stdClass $responses,
    ) {
    }
}
