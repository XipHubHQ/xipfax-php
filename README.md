# XipFax for PHP

Send faxes from your code with [XipFax](https://xipfax.com): quote a fax, add your PDFs, send them to a
number or a government office, and follow each fax until it's delivered. The SDK is generated from
the API's own document, so its methods and types are the API's. It needs PHP 8.1 or later, and
sends its requests with Guzzle 7.

## Install

```sh
composer require xiphub/xipfax-sdk
```

## Make an API key

Sign in to the [developer console](https://developer.xipfax.com/console/), open API keys and make a
key. You see it whole just once, so copy it then. Every key is live: what it sends is real.

Keep the key on your server. A key in a web page or an app is anyone's.

## Use it

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use XipHub\XipFax\Sdk\ApiException;
use XipHub\XipFax\Sdk\Model\ApiError;
use XipHub\XipFax\Sdk\XipFax;

$xipfax = new XipFax(getenv('XIPFAX_API_KEY') ?: '');

try {
    $quote = $xipfax->quote->getQuote(3);
} catch (ApiException $refused) {
    $refusal = $refused->getResponseObject();
    if ($refusal instanceof ApiError) {
        fwrite(STDERR, "{$refusal->getError()->getCode()}: {$refusal->getError()->getMessage()}\n");
        exit(1);
    }
    throw $refused;
}
echo "{$quote->getPriceShown()}: {$quote->getTerms()}\n";
```

The client's APIs are the [API reference](https://developer.xipfax.com/docs/api/)'s sections:
`quote`, `draft`, `faxes`, `plan`, `offices` and `webhookEndpoints`. Each method takes what the
request carries as its arguments, by position or by PHP's names for them,
`listOffices(search: 'Form 2848', place: 'OH', limit: 3)`, and answers the API's answer as its model.
Where the API refuses, it throws an `ApiException`, whose `getCode()` is the HTTP status. For each
refusal the reference gives the method, its `getResponseObject()` is the API's error object, an
`ApiError`, whose error's `getCode()` is the one to branch on; for any other, it's null, and
`getResponseBody()` holds what came. A request that never reached XipFax throws an `ApiException`
with no response.

`new XipFax($apiKey, $baseUrl, $guzzle)` takes, after your key, another address than
`https://api.xipfax.com`, and Guzzle's request options for each request, such as
`['timeout' => 30]`, or the `handler` that sends them.

## The quickstart

[`examples/quickstart.php`](examples/quickstart.php) takes the steps of
[XipFax's quickstart](https://developer.xipfax.com/docs/guides/quickstart/) through the SDK. Run it
from a clone of this repository, after `composer install`:

```sh
XIPFAX_API_KEY=xipfax_key_live_… php examples/quickstart.php
```

That takes the free steps alone: what a fax costs, and the offices that take Form 2848 from Ohio,
with their fax numbers.

To send a fax too, name a fax number of yours and a public https link to a PDF:

```sh
XIPFAX_TO='+1 202 555 0143' XIPFAX_PDF_URL=https://example.com/letter.pdf \
  XIPFAX_API_KEY=xipfax_key_live_… php examples/quickstart.php
```

**That step sends a real fax, and it's charged.** Its pages count on your plan, or where you have no
plan its price is held on the card you saved, and taken once it's delivered. A fax that fails costs
nothing. The quickstart says so before it sends, and sends only once you type `send`. Then it
follows the fax until it's delivered, and gives the link to its confirmation.

## Methods

| Method                                                                            | Request                                        | What it does                                                                         |
| --------------------------------------------------------------------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------ |
| `quote->getQuote($pages)`                                                         | `GET /quote`                                   | The price of a fax of so many pages, sent without a plan.                            |
| `draft->getDraft()`                                                               | `GET /draft`                                   | Your draft, the fax you prepare, its documents in the order it sends them.           |
| `draft->addDocument($idempotencyKey, $newDocument)`                               | `POST /draft/documents`                        | Adds a PDF to the draft, by a link XipFax fetches or by an upload.                   |
| `draft->completeDocument($idempotencyKey, $documentId)`                           | `POST /draft/documents/{document_id}/complete` | Reads an uploaded PDF back, its pages counted.                                       |
| `draft->removeDocument($documentId)`                                              | `DELETE /draft/documents/{document_id}`        | Takes a document out of the draft.                                                   |
| `draft->sendDraft($idempotencyKey, $sendDraft)`                                   | `POST /draft/send`                             | Sends the draft to a number or an office.                                            |
| `faxes->listFaxes($limit, $startingAfter, $direction)`                            | `GET /faxes`                                   | The faxes you sent and received, newest first, a page at a time.                     |
| `faxes->getFax($faxId)`                                                           | `GET /faxes/{fax_id}`                          | A fax, where it stands now.                                                          |
| `faxes->getConfirmation($faxId)`                                                  | `GET /faxes/{fax_id}/confirmation`             | A delivered fax's confirmation, by a link that reads it for a few minutes.           |
| `plan->getPlan()`                                                                 | `GET /plan`                                    | Your plan, or none.                                                                  |
| `offices->listOffices($limit, $startingAfter, $search, $place)`                   | `GET /offices`                                 | The government offices XipFax gives the fax numbers of.                              |
| `webhookEndpoints->registerWebhookEndpoint($idempotencyKey, $newWebhookEndpoint)` | `POST /webhook-endpoints`                      | Registers an endpoint of your server for every event, its signing secret shown once. |
| `webhookEndpoints->removeWebhookEndpoint($endpointId)`                            | `DELETE /webhook-endpoints/{endpoint_id}`      | Takes an endpoint away: no event goes to it from then on.                            |

### Upload a PDF from your disk

Give its size, send its bytes to the link the answer gives, then complete it:

```php
use GuzzleHttp\Client;
use XipHub\XipFax\Sdk\Model\NewDocument;

$pdf = file_get_contents('letter.pdf');
$added = $xipfax->draft->addDocument(
    $addKey,
    new NewDocument(['name' => 'letter.pdf', 'sizeBytes' => strlen($pdf)])
);
$upload = $added->getUpload();
if ($upload === null) {
    throw new RuntimeException('No link to upload to: the request was sent again with its key.');
}
// A client of its own: the link carries its own signature, and your key goes to XipFax alone.
(new Client())->put($upload->getUrl(), ['headers' => $upload->getHeaders(), 'body' => $pdf]);
$xipfax->draft->completeDocument($completeKey, $added->getDocument()->getId());
```

`$addKey` and `$completeKey` are keys of your own, one for each request, as the next section says.

## Retries and `Idempotency-Key`

`addDocument`, `completeDocument`, `sendDraft` and `registerWebhookEndpoint` take an
`Idempotency-Key` of your own, such as a UUID, which you keep with the request. Sent again with the
same key within 24 hours, the request is answered as it was the first time rather than done twice,
so a retry after a timeout can't send a fax twice. The SDK makes up no key itself: one it made would
be new on each try, and protect nothing.

The answer to a request sent again keeps none of its signed links, nor an endpoint's secret:
`getUpload()`, a document's `getUrl()` and an endpoint's `getSecret()` come back null. So an upload
that `addDocument` gave, asked for again with its key, has no link to send the bytes to. Take that
document out with `removeDocument`, and add it again with a new key. An endpoint whose first answer
you lost has no secret to check its deliveries by: remove it with `removeWebhookEndpoint`, and
register it again with a new key.

## Webhooks

`registerWebhookEndpoint` registers an endpoint of your server, which every event goes to from then
on: when a fax you sent is delivered or fails for good, and when a fax sent to your number arrives.
Its answer shows the endpoint's signing secret once, so keep it then. `removeWebhookEndpoint` takes
an endpoint away, as revoking the key that registered it does. Both take an API key, not an app's
token.

Check each delivery's `webhook-signature` with that secret, against its body exactly as it came,
before you trust it. [XipFax's webhooks guide](https://developer.xipfax.com/docs/guides/webhooks/)
shows how. Its body is then a `FaxEvent`, which
`ObjectSerializer::deserialize($body, FaxEvent::class)` reads.

## Versions

Each request names the version of the API the SDK was generated from, `XipFax::API_VERSION`, in its
`Xip-Version` header, so each answer keeps the shape the SDK's models describe. Within that version,
an answer may gain a member, and a set of values a new value: the SDK reads both, and leaves a member
it doesn't know out of the model it answers.

## Where the SDK comes from

`openapi.json` is the API's document, as [api.xipfax.com](https://api.xipfax.com/openapi.json) gives
it, and `scripts/generate.sh` makes the SDK from it with
[openapi-generator](https://openapi-generator.tech) 7.17.0's PHP generator and
`openapi-generator.yaml`. It first writes the document the SDK is generated from, by
`scripts/sdk-document.jq`, which sets aside what the SDK sends itself or doesn't offer: the
document's security, since `XipFax` sends your key on every request, and an app's token is not the
SDK's; the per-request `Xip-Version`, since `XipFax` names the version on every request; and the
webhooks, which are XipFax's requests to your server, their bodies typed as `FaxEvent`. Nothing the
generator writes is edited by hand: `src/XipFax.php` is the hand-written part. To update the SDK,
copy the new document over `openapi.json`, run `scripts/generate.sh`, and commit what changes.
`scripts/gates.sh` checks that the SDK is what the document generates, that Composer takes
`composer.json` and `composer.lock`, and that every file reads and every class loads;
`scripts/test.sh` runs the specs. Both run Composer and PHP in Docker, by `scripts/docker.sh`, on
what `scripts/fetch.sh` fetched.

## Help

Write to [help@xipfax.com](mailto:help@xipfax.com).

## License

[MIT](LICENSE).
