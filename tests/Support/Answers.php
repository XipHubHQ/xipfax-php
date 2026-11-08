<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests\Support;

/** The API's answers the specs are given, in JSON, each as the document gives it. */
final class Answers
{
    /** GET /quote's answer for a fax of one page. */
    public const QUOTE = '{"pages":1,"price":{"amount_minor":100,"currency":"USD"},"price_shown":"$1.00","terms":"The stand-in’s terms.","price_list":"stand-in"}';

    /** An office of GET /offices, which takes Form 2848 from Ohio. */
    public const OFFICE = '{"id":"stand-in-office","name":"A stand-in office","fax_number":"+12025550143","fax_number_from_abroad":null,"applies_to":["Ohio"],"takes":["Form 2848"],"signed_in_ink":false,"confidential_cover":false,"sources":[{"title":"A stand-in page","url":"https://example.com/office"}],"checked_on":"2026-11-01"}';

    /** GET /plan's answer for a client with no plan. */
    public const NO_PLAN = '{"plan":null}';

    /** A document of the draft, ready, by its id and its name. */
    public static function document(string $id, string $name): string
    {
        return self::object([
            'id' => $id,
            'name' => $name,
            'size_bytes' => 20480,
            'page_count' => 2,
            'status' => 'ready',
            'url' => "https://example.com/$id.pdf",
        ]);
    }

    /**
     * fax_1, a fax you sent that was delivered, with each member the changes name in place of its own.
     *
     * @param array<string, mixed> $changes
     */
    public static function fax(array $changes = []): string
    {
        return self::object(array_replace([
            'id' => 'fax_1',
            'direction' => 'sent',
            'status' => 'delivered',
            'step' => null,
            'office_id' => null,
            'to_number' => '+12025550143',
            'from_number' => '+12025550100',
            'pages' => 2,
            'price' => null,
            'sent_at' => '2026-11-07T02:00:00Z',
            'finished_at' => '2026-11-07T02:03:00Z',
            'retry_at' => null,
            'failure' => null,
            'time_zone' => 'America/New_York',
            'sender_name' => null,
            'cover_confidential' => false,
        ], $changes));
    }

    /** The API's refusal, by its code and its sentence. */
    public static function refusal(string $code, string $message): string
    {
        return self::object(['error' => [
            'code' => $code,
            'message' => $message,
            'details' => [],
            'request_id' => 'req_1',
            'doc_url' => null,
        ]]);
    }

    /**
     * The members as a JSON object.
     *
     * @param array<string, mixed> $members
     */
    private static function object(array $members): string
    {
        return json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
