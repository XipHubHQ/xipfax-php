<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use XipHub\XipFax\Sdk\Api\DraftApi;
use XipHub\XipFax\Sdk\Api\FaxesApi;
use XipHub\XipFax\Sdk\Api\OfficesApi;
use XipHub\XipFax\Sdk\Api\PlanApi;
use XipHub\XipFax\Sdk\Api\QuoteApi;
use XipHub\XipFax\Sdk\Api\WebhookEndpointsApi;

/**
 * A client of XipFax's API, made from your API key: each request it sends carries the key as a
 * bearer token and names {@see XipFax::API_VERSION} in its Xip-Version header. Its APIs are the
 * API reference's sections, as `$xipfax->faxes->getFax('fax_1')`.
 */
final class XipFax
{
    /** The version of XipFax's API this SDK is generated from, which every request names. */
    public const API_VERSION = ApiVersion::API_VERSION;

    /** What a fax costs. */
    public readonly QuoteApi $quote;

    /** Your draft, the fax you prepare, and its send. */
    public readonly DraftApi $draft;

    /** The faxes you sent and received. */
    public readonly FaxesApi $faxes;

    /** Your plan. */
    public readonly PlanApi $plan;

    /** The government offices XipFax gives the fax numbers of. */
    public readonly OfficesApi $offices;

    /** The endpoints of your server XipFax posts each event to. */
    public readonly WebhookEndpointsApi $webhookEndpoints;

    /**
     * Makes a client of XipFax's API from your API key, xipfax_key_live_ and 32 more characters,
     * which you make in the developer console and keep on your server.
     *
     * @param string $apiKey your API key
     * @param string|null $baseUrl where the API answers, or null for https://api.xipfax.com
     * @param array<string, mixed> $guzzle Guzzle's request options for each request, such as
     *     `timeout`, and its `handler`, which sends them
     *
     * @throws InvalidArgumentException if the key is empty or only spaces
     */
    public function __construct(string $apiKey, ?string $baseUrl = null, array $guzzle = [])
    {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException(
                'no API key: make one in the developer console, https://developer.xipfax.com/console/'
            );
        }
        $authorization = 'Bearer ' . $apiKey;
        $handler = HandlerStack::create($guzzle['handler'] ?? null);
        // The key and the version go on first, ahead of Guzzle's own steps, so that each request
        // carries its own whatever the options say, and a redirect to another host drops the key.
        $handler->unshift(
            Middleware::mapRequest(
                static fn (RequestInterface $request): RequestInterface => $request
                    ->withHeader('Authorization', $authorization)
                    ->withHeader('Xip-Version', self::API_VERSION)
            ),
            'xipfax'
        );
        $client = new Client(['handler' => $handler] + $guzzle);

        $config = new Configuration();
        if ($baseUrl !== null) {
            $config->setHost(rtrim($baseUrl, '/'));
        }

        $this->quote = new QuoteApi($client, $config);
        $this->draft = new DraftApi($client, $config);
        $this->faxes = new FaxesApi($client, $config);
        $this->plan = new PlanApi($client, $config);
        $this->offices = new OfficesApi($client, $config);
        $this->webhookEndpoints = new WebhookEndpointsApi($client, $config);
    }
}
