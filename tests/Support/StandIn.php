<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests\Support;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use XipHub\XipFax\Sdk\XipFax;

/**
 * Answers the SDK's requests in place of the API, in the specs: Guzzle's handler, under Guzzle's own
 * steps, which keeps each request it is given, with its body, and answers it as its answer says.
 * Nothing it does reaches a network.
 */
final class StandIn
{
    /** @var list<Given> */
    private array $given = [];

    /**
     * @param Closure(RequestInterface, string): ResponseInterface $answer the answer to each request,
     *     given the request and its body
     */
    public function __construct(private readonly Closure $answer)
    {
    }

    /** A stand-in that answers every request with one status and one JSON body; an empty one answers none. */
    public static function same(int $status, string $body): self
    {
        return new self(static fn (): ResponseInterface => self::answer($status, $body));
    }

    /**
     * An answer of a status and a JSON body, its Content-Type application/json where it carries one.
     *
     * @param array<string, string> $headers its other headers, such as a redirect's Location
     */
    public static function answer(int $status, string $body = '', array $headers = []): ResponseInterface
    {
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }
        return new Response($status, $headers, $body);
    }

    /**
     * Keeps the request and answers it as the answer says.
     *
     * @param array<string, mixed> $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $body = (string) $request->getBody();
        $this->given[] = new Given($request, $body, $options);
        return Create::promiseFor(($this->answer)($request, $body));
    }

    /**
     * @return list<Given> each request the stand-in was given, in its order
     */
    public function requests(): array
    {
        return $this->given;
    }

    /**
     * A client of the key whose every request goes to the stand-in.
     *
     * @param array<string, mixed> $guzzle Guzzle's other options for each request
     */
    public function client(string $apiKey, ?string $baseUrl = null, array $guzzle = []): XipFax
    {
        return new XipFax($apiKey, $baseUrl, ['handler' => $this] + $guzzle);
    }
}
