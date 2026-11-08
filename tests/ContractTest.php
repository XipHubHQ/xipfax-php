<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests;

use Closure;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XipHub\XipFax\Sdk\Model\Fax;
use XipHub\XipFax\Sdk\Model\FaxEvent;
use XipHub\XipFax\Sdk\Model\NewDocument;
use XipHub\XipFax\Sdk\Model\NewWebhookEndpoint;
use XipHub\XipFax\Sdk\Model\SendDraft;
use XipHub\XipFax\Sdk\ObjectSerializer;
use XipHub\XipFax\Sdk\Tests\Support\Answers;
use XipHub\XipFax\Sdk\Tests\Support\Document;
use XipHub\XipFax\Sdk\Tests\Support\Operation;
use XipHub\XipFax\Sdk\Tests\Support\StandIn;
use XipHub\XipFax\Sdk\XipFax;

/**
 * Each of the SDK's methods sends the request the API's document asks for: its method and path on the
 * document's server, the key as a bearer token, the version, and every parameter and body as the
 * document's schemas take them. Each request a call sends is read against the document itself, as
 * each answer the stand-in gives is. Each event the document posts is read by the SDK as a FaxEvent,
 * which the document takes as the SDK writes it.
 */
final class ContractTest extends TestCase
{
    private const API_KEY = 'xipfax_key_live_example';

    private static ?Document $document = null;

    /** The API's document, as the repository holds it. */
    private static function document(): Document
    {
        return self::$document ??= Document::load(__DIR__ . '/../openapi.json');
    }

    /**
     * One call of each operation, by its operation id, with arguments the document takes.
     *
     * @return array<string, Closure(XipFax): mixed>
     */
    private static function calls(): array
    {
        return [
            'getQuote' => static fn (XipFax $xipfax): mixed => $xipfax->quote->getQuote(3),
            'getDraft' => static fn (XipFax $xipfax): mixed => $xipfax->draft->getDraft(),
            'addDocument' => static fn (XipFax $xipfax): mixed => $xipfax->draft->addDocument(
                'add-letter-1',
                new NewDocument(['name' => 'letter.pdf', 'url' => 'https://example.com/letter.pdf'])
            ),
            'completeDocument' => static fn (XipFax $xipfax): mixed => $xipfax->draft->completeDocument('complete-letter-1', 'doc_1'),
            'removeDocument' => static fn (XipFax $xipfax): mixed => $xipfax->draft->removeDocument('doc_1'),
            'sendDraft' => static fn (XipFax $xipfax): mixed => $xipfax->draft->sendDraft(
                'send-letter-1',
                new SendDraft(['faxNumber' => '+1 202 555 0143', 'documentIds' => ['doc_1']])
            ),
            'listFaxes' => static fn (XipFax $xipfax): mixed => $xipfax->faxes->listFaxes(20, 'fax_0', 'sent'),
            'getFax' => static fn (XipFax $xipfax): mixed => $xipfax->faxes->getFax('fax_1'),
            'getConfirmation' => static fn (XipFax $xipfax): mixed => $xipfax->faxes->getConfirmation('fax_1'),
            'getPlan' => static fn (XipFax $xipfax): mixed => $xipfax->plan->getPlan(),
            'listOffices' => static fn (XipFax $xipfax): mixed => $xipfax->offices->listOffices(
                search: 'Form 2848',
                place: 'OH',
                limit: 5,
                startingAfter: 'office_0',
            ),
            'registerWebhookEndpoint' => static fn (XipFax $xipfax): mixed => $xipfax->webhookEndpoints->registerWebhookEndpoint(
                'register-endpoint-1',
                new NewWebhookEndpoint(['url' => 'https://example.com/xipfax/events'])
            ),
            'removeWebhookEndpoint' => static fn (XipFax $xipfax): mixed => $xipfax->webhookEndpoints->removeWebhookEndpoint('endpoint_1'),
        ];
    }

    /**
     * What the stand-in answers each operation's success with, by its operation id: none for the two
     * that answer 204.
     *
     * @return array<string, string>
     */
    private static function answers(): array
    {
        return [
            'getQuote' => Answers::QUOTE,
            'getDraft' => '{"id":"fax_1","documents":[]}',
            'addDocument' => '{"document":' . Answers::document('doc_1', 'letter.pdf') . ',"upload":null}',
            'completeDocument' => Answers::document('doc_1', 'letter.pdf'),
            'sendDraft' => '{"fax":' . Answers::fax(['status' => 'sending', 'step' => 'queued', 'finished_at' => null])
                . ',"paid_by":"plan","confirm_url":null}',
            'listFaxes' => '{"data":[' . Answers::fax() . '],"has_more":false,"next_cursor":null}',
            'getFax' => Answers::fax(),
            'getConfirmation' => '{"url":"https://example.com/confirmation.pdf"}',
            'getPlan' => Answers::NO_PLAN,
            'listOffices' => '{"data":[' . Answers::OFFICE . '],"has_more":false,"next_cursor":null}',
            'registerWebhookEndpoint' => '{"id":"endpoint_1","url":"https://example.com/xipfax/events",'
                . '"created_at":"2026-11-07T02:00:00Z","secret":"whsec_c3RhbmQtaW4="}',
        ];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function operations(): iterable
    {
        foreach (self::document()->operations as $operation) {
            yield $operation->id => [$operation->id, $operation->success];
        }
    }

    public function testTheSdkHasACallForEveryOperationTheDocumentNamesAndNoOther(): void
    {
        $ids = array_map(static fn (Operation $operation): string => $operation->id, self::document()->operations);
        $named = array_keys(self::calls());
        sort($ids, SORT_STRING);
        sort($named, SORT_STRING);

        self::assertSame($ids, $named);
    }

    public function testTheSdkNamesTheVersionTheDocumentIsTheOneXipVersionTakes(): void
    {
        $document = self::document();

        self::assertSame($document->version, XipFax::API_VERSION);
        foreach ($document->operations as $operation) {
            self::assertSame([], $document->versionRefused($operation, XipFax::API_VERSION), $operation->id);
        }
    }

    #[DataProvider('operations')]
    public function testEachCallSendsItsRequestAsTheDocumentAsks(string $id, int $success): void
    {
        $document = self::document();
        $calls = self::calls();
        self::assertArrayHasKey($id, $calls, "no call of $id");
        $answer = self::answers()[$id] ?? '';
        $standIn = StandIn::same($success, $answer);

        $calls[$id]($standIn->client(self::API_KEY));

        $given = $standIn->requests();
        self::assertCount(1, $given, "$id sent other than one request");
        $request = $given[0]->request;
        self::assertSame(['Bearer ' . self::API_KEY], $request->getHeader('Authorization'));
        self::assertSame(
            [$id, []],
            $document->readRequest($request, $given[0]->body, XipFax::API_VERSION),
            "{$request->getMethod()} {$request->getUri()} reads otherwise"
        );
        self::assertSame([], $document->readAnswer($request, $success, $answer), "the stand-in's answer is refused");
    }

    public function testEachEventTheDocumentPostsIsAFaxEventItTakes(): void
    {
        $document = self::document();
        // The fax each event tells of, by the event's type: one for each type the document posts.
        $faxes = [
            'fax.delivered' => Answers::fax(['price' => ['amount_minor' => 100, 'currency' => 'USD']]),
            'fax.failed' => Answers::fax(['status' => 'failed', 'failure' => 'busy']),
            'fax.received' => Answers::fax([
                'id' => 'fax_2',
                'direction' => 'received',
                'status' => 'received',
                'from_number' => '+12025550111',
                'to_number' => '+12025550100',
                'pages' => 3,
                'finished_at' => null,
                'time_zone' => null,
            ]),
        ];
        $posted = array_keys($document->webhooks);
        $typed = array_keys($faxes);
        sort($posted, SORT_STRING);
        sort($typed, SORT_STRING);
        self::assertSame($posted, $typed, 'the document posts other events than the spec types');

        foreach ($faxes as $name => $fax) {
            $data = ObjectSerializer::deserialize($fax, Fax::class);
            self::assertInstanceOf(Fax::class, $data, "$name: the SDK doesn't read its fax");
            $event = new FaxEvent([
                'id' => 'event_1',
                'type' => $name,
                'apiVersion' => XipFax::API_VERSION,
                'createdAt' => '2026-11-07T02:03:00Z',
                'data' => $data,
            ]);
            $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $value = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $document->check($document->webhooks[$name], $value, $name), "$name as the SDK writes it is refused");
            $read = ObjectSerializer::deserialize($body, FaxEvent::class);
            self::assertInstanceOf(FaxEvent::class, $read, "$name: the SDK doesn't read back the event it wrote");
            self::assertSame($data->getId(), $read->getData()->getId());
        }
    }

    // The reader is held to failing too: each thing the document refuses in a request or an answer is
    // refused, by name, so that a spec that reads the SDK's requests as fitting can fail.

    /**
     * @return iterable<string, array{string, string, array<string, string|list<string>>, string, string, list<string>}>
     */
    public static function wrongRequests(): iterable
    {
        $signed = static fn (array $more = []): array => $more + [
            'Authorization' => 'Bearer ' . self::API_KEY,
            'Xip-Version' => XipFax::API_VERSION,
        ];
        $json = ['Content-Type' => 'application/json', 'Idempotency-Key' => 'add-letter-1'];

        yield 'a request it takes' => [
            'GET', 'https://api.xipfax.com/quote?pages=3', $signed(), '', 'getQuote', [],
        ];
        yield 'no key, the version twice, a page past the most and a parameter it doesn\'t take' => [
            'GET',
            'https://api.xipfax.com/quote?pages=251&color=red',
            ['Xip-Version' => [XipFax::API_VERSION, XipFax::API_VERSION]],
            '',
            'getQuote',
            [
                'Authorization is [], not one bearer key',
                sprintf('Xip-Version is ["%1$s" "%1$s"], not %1$s', XipFax::API_VERSION),
                'header Xip-Version is sent 2 times',
                'query pages is 251, past its maximum of 250',
                'query color is no parameter of getQuote',
            ],
        ];
        yield 'a body with a member it doesn\'t take, a size under the least and no name' => [
            'POST',
            'https://api.xipfax.com/draft/documents',
            $signed($json),
            '{"size_bytes":0,"colour":"red"}',
            'addDocument',
            [
                'body.colour is no member it takes',
                'body.size_bytes is 0, past its minimum of 1',
                'body.name is missing',
            ],
        ];
        yield 'a name that ends in a line break' => [
            'POST',
            'https://api.xipfax.com/draft/documents',
            $signed($json),
            '{"name":"letter.pdf\n","url":"https://example.com/letter.pdf"}',
            'addDocument',
            ['body.name is "letter.pdf\n", not of the pattern ^[^\p{Cc}\p{Zl}\p{Zp}]+$'],
        ];
        yield 'a body where the operation takes none' => [
            'DELETE', 'https://api.xipfax.com/draft/documents/doc_1', $signed(), '{}', 'removeDocument',
            ['a body the operation takes none of'],
        ];
        yield 'another server' => [
            'GET', 'https://api.example.com/quote?pages=3', $signed(), '', '',
            ['GET https://api.example.com/quote?pages=3 is no operation of the document'],
        ];
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param list<string> $refused
     */
    #[DataProvider('wrongRequests')]
    public function testTheReaderRefusesEachThingARequestGetsWrong(
        string $method,
        string $address,
        array $headers,
        string $body,
        string $id,
        array $refused,
    ): void {
        $request = new Request($method, $address, $headers, $body);

        self::assertSame([$id, $refused], self::document()->readRequest($request, $body, XipFax::API_VERSION));
    }

    /**
     * @return iterable<string, array{string, string, int, string, list<string>}>
     */
    public static function wrongAnswers(): iterable
    {
        yield 'a fax whose pages are words and whose status the document doesn\'t list' => [
            'GET',
            'https://api.xipfax.com/faxes/fax_1',
            200,
            Answers::fax(['pages' => 'two', 'status' => 'archived']),
            [
                'answer.pages is string, not [integer null]',
                'answer.status is archived, none of [draft sending delivered failed received]',
            ],
        ];
        yield 'a status the operation doesn\'t give' => [
            'GET', 'https://api.xipfax.com/plan', 418, '', ['getPlan gives no 418'],
        ];
        yield 'a body where the answer carries none' => [
            'DELETE', 'https://api.xipfax.com/draft/documents/doc_1', 204, '{}', ["removeDocument's 204 carries no body"],
        ];
    }

    /**
     * @param list<string> $refused
     */
    #[DataProvider('wrongAnswers')]
    public function testTheReaderRefusesEachThingAnAnswerGetsWrong(
        string $method,
        string $address,
        int $status,
        string $answer,
        array $refused,
    ): void {
        self::assertSame($refused, self::document()->readAnswer(new Request($method, $address), $status, $answer));
    }

    public function testTheReaderRefusesASchemaKeywordItDoesntRead(): void
    {
        $schema = json_decode('{"oneOf":[{"type":"string"}]}', false, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['value: the contract reads no oneOf, which its schema gives'],
            self::document()->check($schema, 'text', 'value')
        );
    }
}
