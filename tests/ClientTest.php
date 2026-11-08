<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use XipHub\XipFax\Sdk\ApiException;
use XipHub\XipFax\Sdk\Model\ApiError;
use XipHub\XipFax\Sdk\Tests\Support\Answers;
use XipHub\XipFax\Sdk\Tests\Support\StandIn;
use XipHub\XipFax\Sdk\XipFax;

/** XipFax, the client: the key and the version on every request, and the API's refusals as they come. */
final class ClientTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function noKeys(): iterable
    {
        yield 'an empty key' => [''];
        yield 'a key of spaces' => ['   '];
    }

    #[DataProvider('noKeys')]
    public function testItRefusesToStartWithoutAKey(string $apiKey): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no API key: make one in the developer console, https://developer.xipfax.com/console/');

        new XipFax($apiKey);
    }

    public function testItSendsToTheApisOwnAddressUnlessGivenAnother(): void
    {
        $own = StandIn::same(200, Answers::NO_PLAN);
        $own->client('key-a')->plan->getPlan();
        self::assertSame('https://api.xipfax.com/plan', (string) $own->requests()[0]->request->getUri());

        $other = StandIn::same(200, Answers::NO_PLAN);
        $other->client('key-a', 'https://api.example.com/')->plan->getPlan();
        self::assertSame('https://api.example.com/plan', (string) $other->requests()[0]->request->getUri(), 'not the address named');
    }

    public function testEachClientKeepsItsKeyToItself(): void
    {
        $a = StandIn::same(200, Answers::NO_PLAN);
        $b = StandIn::same(200, Answers::NO_PLAN);
        $first = $a->client('key-a');
        $second = $b->client('key-b');

        $first->plan->getPlan();
        $second->plan->getPlan();

        foreach ([[$a, 'Bearer key-a'], [$b, 'Bearer key-b']] as [$standIn, $authorization]) {
            $given = $standIn->requests();
            self::assertCount(1, $given, 'requests went where one should have');
            self::assertSame([$authorization], $given[0]->request->getHeader('Authorization'));
        }
    }

    public function testEachRequestNamesTheVersionTheSdkIsGeneratedFrom(): void
    {
        $standIn = StandIn::same(200, Answers::NO_PLAN);
        $standIn->client('key-a')->plan->getPlan();

        self::assertSame([XipFax::API_VERSION], $standIn->requests()[0]->request->getHeader('Xip-Version'));
    }

    public function testGuzzlesOptionsGoWithEachRequest(): void
    {
        $standIn = StandIn::same(200, Answers::NO_PLAN);
        $standIn->client('key-a', null, ['timeout' => 30])->plan->getPlan();

        self::assertSame(30, $standIn->requests()[0]->options['timeout'] ?? null);
    }

    public function testARedirectToAnotherHostTakesTheKeyOff(): void
    {
        $standIn = new StandIn(static fn (RequestInterface $request): ResponseInterface => $request->getUri()->getHost() === 'api.xipfax.com'
            ? StandIn::answer(302, '', ['Location' => 'https://elsewhere.example.com/plan'])
            : StandIn::answer(200, Answers::NO_PLAN));

        $standIn->client('key-a')->plan->getPlan();

        $given = $standIn->requests();
        self::assertCount(2, $given);
        self::assertSame(['Bearer key-a'], $given[0]->request->getHeader('Authorization'));
        self::assertSame('https://elsewhere.example.com/plan', (string) $given[1]->request->getUri());
        self::assertSame([], $given[1]->request->getHeader('Authorization'), 'the key went to another host');
    }

    public function testARefusalTheReferenceGivesIsTheApisOwnError(): void
    {
        $standIn = StandIn::same(401, Answers::refusal('authentication_failed', 'That key isn’t one of XipFax’s.'));

        try {
            $standIn->client('key-a')->plan->getPlan();
            self::fail('a refusal answered');
        } catch (ApiException $refused) {
            self::assertSame(401, $refused->getCode());
            $refusal = $refused->getResponseObject();
            self::assertInstanceOf(ApiError::class, $refusal);
            self::assertSame('authentication_failed', $refusal->getError()->getCode());
            self::assertSame('req_1', $refusal->getError()->getRequestId());
        }
    }

    public function testAnyOtherRefusalKeepsWhatCameInItsBody(): void
    {
        // GET /plan's reference gives no 429, so the SDK reads no error object from it.
        $body = Answers::refusal('rate_limited', 'Too many requests: try again in a minute.');
        $standIn = StandIn::same(429, $body);

        try {
            $standIn->client('key-a')->plan->getPlan();
            self::fail('a refusal answered');
        } catch (ApiException $refused) {
            self::assertSame(429, $refused->getCode());
            self::assertNull($refused->getResponseObject());
            self::assertSame($body, $refused->getResponseBody());
        }
    }

    public function testARequestThatNeverReachedXipFaxThrowsWithNoResponse(): void
    {
        $xipfax = new XipFax('key-a', null, [
            'handler' => static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
                new ConnectException('Connection refused', $request)
            ),
        ]);

        try {
            $xipfax->plan->getPlan();
            self::fail('a request that never reached XipFax answered');
        } catch (ApiException $failed) {
            self::assertNull($failed->getResponseObject());
            self::assertNull($failed->getResponseHeaders());
            self::assertNull($failed->getResponseBody());
        }
    }

    public function testAnAnswerThatGainsAMemberIsReadWithTheMemberLeftOut(): void
    {
        // Within a version, an answer may gain a member, and an open set a new value. Here the fax
        // gains `tracking`, and its status is one the SDK has never seen: the SDK reads the status as
        // the text it is, and leaves the member it doesn't know out of the model it answers.
        $gained = Answers::fax(['status' => 'archived', 'tracking' => ['carrier' => 'stand-in']]);

        $fax = StandIn::same(200, $gained)->client('key-a')->faxes->getFax('fax_1');

        self::assertSame('fax_1', $fax->getId());
        self::assertSame('archived', $fax->getStatus());
        $again = json_decode(json_encode($fax, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertObjectNotHasProperty('tracking', $again);
    }
}
