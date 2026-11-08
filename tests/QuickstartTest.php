<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use XipHub\XipFax\Sdk\ApiException;
use XipHub\XipFax\Sdk\Examples\QuickstartSteps;
use XipHub\XipFax\Sdk\Model\ApiError;
use XipHub\XipFax\Sdk\Tests\Support\Answers;
use XipHub\XipFax\Sdk\Tests\Support\Document;
use XipHub\XipFax\Sdk\Tests\Support\Given;
use XipHub\XipFax\Sdk\Tests\Support\StandIn;
use XipHub\XipFax\Sdk\XipFax;

/**
 * The quickstart, walked against a stand-in of the API that answers as the document says: its free
 * steps first, its charged step said before it and taken only on send, and each request it sends
 * read against the document, as each answer the stand-in gives is.
 */
final class QuickstartTest extends TestCase
{
    private const API_KEY = 'xipfax_key_live_example';

    private const FAX_NUMBER = '+1 202 555 0143';

    private const PDF_LINK = 'https://example.com/letter.pdf';

    /**
     * Walks the quickstart against the API, its draft holding the draft's documents and gaining those
     * added on xipfax.com once the quickstart adds its own, the typed text being what you type when it
     * asks; and reads each request it sent and each answer it was given against the document.
     *
     * @param list<string> $draft
     * @param list<string> $addedOnXipfax
     * @return array{events: list<string>, given: list<Given>, pauses: list<int>, refused: ApiException|null}
     */
    private function walk(array $draft, array $addedOnXipfax, string $typed, string $to, string $pdfUrl): array
    {
        $document = Document::load(__DIR__ . '/../openapi.json');
        $events = [];
        $pauses = [];
        $faults = [];
        $documents = $draft;
        $reads = 0;
        $standIn = new StandIn(
            static function (RequestInterface $request, string $body) use (&$events, &$faults, &$documents, &$reads, $addedOnXipfax, $document): ResponseInterface {
                $route = $request->getMethod() . ' ' . $request->getUri()->getPath();
                $events[] = $route;
                [$status, $answer] = self::route($route, $body, $documents, $reads, $addedOnXipfax);
                $refused = $document->readAnswer($request, $status, $answer);
                if ($refused !== []) {
                    $faults[] = "the stand-in's answer to $route is refused: " . implode('; ', $refused);
                }
                return StandIn::answer($status, $answer);
            }
        );

        $steps = new QuickstartSteps(
            $standIn->client(self::API_KEY),
            $to,
            $pdfUrl,
            say: static function (string $line) use (&$events): void {
                $events[] = "say: $line";
            },
            ask: static function (string $question) use (&$events, $typed): string {
                $events[] = "ask: $question";
                return $typed;
            },
            pause: static function (int $seconds) use (&$pauses): void {
                $pauses[] = $seconds;
            },
        );
        $refusedBy = null;
        try {
            $steps->run();
        } catch (ApiException $refused) {
            $refusedBy = $refused;
        }

        foreach ($standIn->requests() as $given) {
            [, $refused] = $document->readRequest($given->request, $given->body, XipFax::API_VERSION);
            if ($refused !== []) {
                $faults[] = "{$given->request->getMethod()} {$given->request->getUri()}: " . implode('; ', $refused);
            }
        }
        self::assertSame([], $faults);
        return ['events' => $events, 'given' => $standIn->requests(), 'pauses' => $pauses, 'refused' => $refusedBy];
    }

    /**
     * The API's answer to a route, as the API would give it to the quickstart.
     *
     * @param list<string> $documents the draft's documents, by their ids
     * @param list<string> $addedOnXipfax
     * @return array{int, string}
     */
    private static function route(string $route, string $body, array &$documents, int &$reads, array $addedOnXipfax): array
    {
        switch ($route) {
            case 'GET /quote':
                return [200, Answers::QUOTE];
            case 'GET /offices':
                return [200, '{"data":[' . Answers::OFFICE . '],"has_more":false,"next_cursor":null}'];
            case 'GET /draft':
                $listed = array_map(static fn (string $id): string => Answers::document($id, 'letter.pdf'), $documents);
                return [200, '{"id":"fax_1","documents":[' . implode(',', $listed) . ']}'];
            case 'POST /draft/documents':
                $documents = [...$documents, 'doc_1', ...$addedOnXipfax];
                return [201, '{"document":' . Answers::document('doc_1', 'letter.pdf') . ',"upload":null}'];
            case 'DELETE /draft/documents/doc_1':
                $documents = array_values(array_filter($documents, static fn (string $id): bool => $id !== 'doc_1'));
                return [204, ''];
            case 'POST /draft/send':
                // As the API does, it sends a draft only where the send names its documents, in order.
                $sent = json_decode($body, true);
                if (!is_array($sent) || ($sent['document_ids'] ?? null) !== $documents) {
                    return [409, Answers::refusal('draft_changed', 'Your fax changed since this page showed it. Look it over, then send it again.')];
                }
                return [200, '{"fax":' . Answers::fax(['status' => 'sending', 'step' => 'queued', 'finished_at' => null])
                    . ',"paid_by":"plan","confirm_url":null}'];
            case 'GET /faxes/fax_1':
                $reads++;
                if ($reads < 2) {
                    return [200, Answers::fax(['status' => 'sending', 'step' => 'sending', 'finished_at' => null])];
                }
                return [200, Answers::fax()];
            case 'GET /faxes/fax_1/confirmation':
                return [200, '{"url":"https://example.com/confirmation.pdf"}'];
        }
        return [404, ''];
    }

    /**
     * The walk's requests alone, by their routes.
     *
     * @param list<string> $events
     * @return list<string>
     */
    private static function requests(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn (string $event): bool => !str_starts_with($event, 'say: ') && !str_starts_with($event, 'ask: ')
        ));
    }

    /**
     * The body of the walk's POST /draft/send, its members in the order of their names.
     *
     * @param list<Given> $given
     * @return array<string, mixed>
     */
    private static function sent(array $given): array
    {
        foreach ($given as $request) {
            if ($request->request->getMethod() === 'POST' && $request->request->getUri()->getPath() === '/draft/send') {
                $body = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                ksort($body);
                return $body;
            }
        }
        self::fail('the walk sent no POST /draft/send');
    }

    /**
     * The index of the first event that is the event, or that starts with it where it ends in a space.
     *
     * @param list<string> $events
     */
    private static function indexOf(array $events, string $event): int
    {
        foreach ($events as $index => $seen) {
            if ($seen === $event || (str_ends_with($event, ' ') && str_starts_with($seen, $event))) {
                return $index;
            }
        }
        return -1;
    }

    public function testTheQuickstartTakesTheFreeStepsAloneWhereItHasNoNumberOrPdf(): void
    {
        $walk = $this->walk([], [], 'send', '', '');

        self::assertNull($walk['refused']);
        self::assertSame(['GET /quote', 'GET /offices'], self::requests($walk['events']));
        self::assertSame(-1, self::indexOf($walk['events'], 'ask: '), 'it asked, with no number or PDF');
    }

    public function testTheQuickstartSaysTheFaxIsRealAndChargedSendsItOnlyOnceSendIsTypedAndFollowsIt(): void
    {
        $walk = $this->walk([], [], 'send', self::FAX_NUMBER, self::PDF_LINK);

        self::assertNull($walk['refused']);
        self::assertSame([
            'GET /quote',
            'GET /offices',
            'GET /draft',
            'POST /draft/documents',
            'POST /draft/send',
            'GET /faxes/fax_1',
            'GET /faxes/fax_1',
            'GET /faxes/fax_1/confirmation',
        ], self::requests($walk['events']));
        $added = self::indexOf($walk['events'], 'POST /draft/documents');
        $warned = self::indexOf($walk['events'], 'say: ' . QuickstartSteps::REAL_AND_CHARGED);
        $asked = self::indexOf($walk['events'], 'ask: ');
        $sent = self::indexOf($walk['events'], 'POST /draft/send');
        self::assertTrue(
            $added >= 0 && $warned > $added && $asked > $warned && $sent > $asked,
            "it added at $added, warned at $warned, asked at $asked and sent at $sent"
        );
        self::assertContains('say: Added letter.pdf, 2 pages.', $walk['events'], "it didn't say what it added");
        self::assertSame([QuickstartSteps::READ_EVERY, QuickstartSteps::READ_EVERY], $walk['pauses'], 'it read the fax without waiting between reads');

        self::assertSame(['document_ids' => ['doc_1'], 'fax_number' => self::FAX_NUMBER], self::sent($walk['given']));
        $keys = [];
        $posts = 0;
        foreach ($walk['given'] as $given) {
            if ($given->request->getMethod() === 'POST') {
                $keys[$given->request->getHeaderLine('Idempotency-Key')] = true;
                $posts++;
            }
        }
        self::assertCount($posts, $keys, "$posts POSTs named other than as many keys");
    }

    public function testTheQuickstartSendsNothingOnAnyOtherAnswerAndTakesItsDocumentBackOutOfTheDraft(): void
    {
        $walk = $this->walk([], [], 'no', self::FAX_NUMBER, self::PDF_LINK);

        self::assertNull($walk['refused']);
        self::assertSame(
            ['GET /quote', 'GET /offices', 'GET /draft', 'POST /draft/documents', 'DELETE /draft/documents/doc_1'],
            self::requests($walk['events'])
        );
    }

    public function testTheQuickstartNamesOnlyTheDocumentItAddedSoADraftThatGainedAnotherOnXipFaxIsRefused(): void
    {
        $walk = $this->walk([], ['doc_2'], 'send', self::FAX_NUMBER, self::PDF_LINK);

        self::assertInstanceOf(ApiException::class, $walk['refused'], "it answered, not the API's refusal");
        $refusal = $walk['refused']->getResponseObject();
        self::assertInstanceOf(ApiError::class, $refusal);
        self::assertSame('draft_changed', $refusal->getError()->getCode());
        self::assertSame(
            ['GET /quote', 'GET /offices', 'GET /draft', 'POST /draft/documents', 'POST /draft/send'],
            self::requests($walk['events'])
        );
        self::assertSame(['document_ids' => ['doc_1'], 'fax_number' => self::FAX_NUMBER], self::sent($walk['given']));
    }

    public function testTheQuickstartAddsNothingToADraftThatAlreadyHoldsDocuments(): void
    {
        $walk = $this->walk(['doc_0'], [], 'send', self::FAX_NUMBER, self::PDF_LINK);

        self::assertNull($walk['refused']);
        self::assertSame(['GET /quote', 'GET /offices', 'GET /draft'], self::requests($walk['events']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function links(): iterable
    {
        yield 'a name' => ['https://example.com/letter.pdf', 'letter.pdf'];
        yield 'a name percent-encoded' => ['https://example.com/forms/Form%202848.pdf', 'Form 2848.pdf'];
        yield 'a folder' => ['https://example.com/forms/', 'forms'];
        yield 'no path' => ['https://example.com', 'document.pdf'];
        yield 'an encoding of no text' => ['https://example.com/%FF.pdf', '%FF.pdf'];
        yield 'a name past 255 characters' => ['https://example.com/' . str_repeat('é', 300), str_repeat('é', 255)];
    }

    #[DataProvider('links')]
    public function testTheDocumentIsNamedAsItsLinkEnds(string $link, string $name): void
    {
        self::assertSame($name, QuickstartSteps::nameOf($link));
    }
}
