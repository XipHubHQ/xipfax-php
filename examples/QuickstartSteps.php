<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Examples;

use Closure;
use XipHub\XipFax\Sdk\ApiException;
use XipHub\XipFax\Sdk\Model\DraftDocument;
use XipHub\XipFax\Sdk\Model\NewDocument;
use XipHub\XipFax\Sdk\Model\SendDraft;
use XipHub\XipFax\Sdk\XipFax;

/**
 * XipFax's quickstart through the SDK, the steps of https://developer.xipfax.com/docs/guides/quickstart/
 * in their order. The free steps come first: a quote, and the offices that take a form. Then, only
 * where you name a fax number of yours and a PDF, a real fax, charged, which it says before it sends,
 * and sends only once you type send. quickstart.php runs it in your terminal.
 */
final class QuickstartSteps
{
    /** How long the quickstart waits between reads of the fax, in seconds. */
    public const READ_EVERY = 10;

    /** How many reads of the fax the quickstart makes at most. */
    public const READS = 30;

    /** What the step that sends says first, as the docs' quickstart says it. */
    public const REAL_AND_CHARGED = 'This step sends a real fax, and it’s charged. It goes to the number you name, so name one of your own. Its pages count on your plan, or where you have no plan its price is held on the card you saved, and taken once it’s delivered. A fax that fails costs nothing.';

    /**
     * @param XipFax $xipfax the client that sends each request
     * @param string $to a fax number of yours, where the fax goes; without it, only the free steps run
     * @param string $pdfUrl a public https link to a PDF, the fax's pages; without it, only the free steps run
     * @param Closure(string): void $say tells you a line
     * @param Closure(string): string $ask asks you a question, answering what you typed
     * @param Closure(int): void $pause waits so many seconds between reads of the fax
     */
    public function __construct(
        private readonly XipFax $xipfax,
        private readonly string $to,
        private readonly string $pdfUrl,
        private readonly Closure $say,
        private readonly Closure $ask,
        private readonly Closure $pause,
    ) {
    }

    /**
     * Takes the quickstart's steps. The API's refusal stops it, thrown as the ApiException whose
     * response object is the API's own, which quickstart.php prints.
     *
     * @throws ApiException where the API refuses a step
     */
    public function run(): void
    {
        // 1. What a fax costs. This costs nothing, and it shows your key works.
        $quote = $this->xipfax->quote->getQuote(1);
        $this->say("A one-page fax costs {$quote->getPriceShown()} without a plan: {$quote->getTerms()}");

        // 2. Where a form goes: the offices that take Form 2848 from Ohio, and their numbers. Also free.
        $offices = $this->xipfax->offices->listOffices(search: 'Form 2848', place: 'OH', limit: 3);
        foreach ($offices->getData() as $office) {
            $this->say("{$office->getName()} takes Form 2848 at {$office->getFaxNumber()}.");
        }

        if ($this->to === '' || $this->pdfUrl === '') {
            return;
        }

        // 3. Your draft, the fax you prepare. This sends only its own document, so it starts empty.
        $before = $this->xipfax->draft->getDraft();
        if ($before->getDocuments() !== []) {
            $this->say('Your draft already holds documents. Send or remove them on xipfax.com first, so that this sends only its own.');
            return;
        }

        // 4. Add the PDF by its link. Each POST names a key of its own, so a retry can't add it twice.
        $added = $this->xipfax->draft->addDocument(
            self::newKey(),
            new NewDocument(['name' => self::nameOf($this->pdfUrl), 'url' => $this->pdfUrl])
        );
        $document = $added->getDocument();
        $this->say("Added {$document->getName()}" . self::pagesOf($document) . '.');

        // 5. Send it, a real fax, charged: said first, and sent only once you type send.
        $this->say(self::REAL_AND_CHARGED);
        $answer = ($this->ask)("Type send to fax it to {$this->to}, or anything else to stop: ");
        if (strtolower(trim($answer)) !== 'send') {
            $this->xipfax->draft->removeDocument($document->getId());
            $this->say('Nothing was sent, and the document is out of your draft again.');
            return;
        }
        // The send names the documents to send: the one this added, alone. A draft whose documents
        // aren't these is refused, so that nothing you didn't name is sent.
        $sent = $this->xipfax->draft->sendDraft(
            self::newKey(),
            new SendDraft(['faxNumber' => $this->to, 'documentIds' => [$document->getId()]])
        );
        $confirmUrl = $sent->getConfirmUrl();
        if ($confirmUrl !== null && $confirmUrl !== '') {
            $this->say("Nothing was sent or held: the fax waits for you on xipfax.com, $confirmUrl");
            return;
        }
        $fax = $sent->getFax();
        $this->say("Sent, paid by {$sent->getPaidBy()}: fax {$fax->getId()}.");

        // 6. Follow the fax until it's delivered or fails, then read its confirmation.
        $shown = '';
        for ($read = 0; $read < self::READS && !in_array($fax->getStatus(), ['delivered', 'failed'], true); $read++) {
            ($this->pause)(self::READ_EVERY);
            $fax = $this->xipfax->faxes->getFax($fax->getId());
            $stands = $fax->getStatus();
            $step = $fax->getStep();
            if ($step !== null && $step !== '') {
                $stands .= ": $step";
            }
            if ($stands !== $shown) {
                $this->say($stands);
            }
            $shown = $stands;
        }
        switch ($fax->getStatus()) {
            case 'delivered':
                $confirmation = $this->xipfax->faxes->getConfirmation($fax->getId());
                $this->say("Its confirmation, readable for a few minutes: {$confirmation->getUrl()}");
                break;
            case 'failed':
                $this->say("It failed, {$fax->getFailure()}, so it costs nothing.");
                break;
            default:
                $this->say("It's still {$fax->getStatus()}. Read it later: \$xipfax->faxes->getFax('{$fax->getId()}').");
        }
    }

    /** The PDF's name, as its link ends: letter.pdf, or document.pdf where its path names none. */
    public static function nameOf(string $link): string
    {
        $name = 'document.pdf';
        $path = parse_url($link, PHP_URL_PATH);
        $segments = is_string($path) ? array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '')) : [];
        if ($segments !== []) {
            $name = $segments[count($segments) - 1];
            // A link whose name isn't percent-encoded text keeps it as it is.
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $name) !== 1) {
                $text = rawurldecode($name);
                if (mb_check_encoding($text, 'UTF-8')) {
                    $name = $text;
                }
            }
        }
        return mb_strlen($name, 'UTF-8') > 255 ? mb_substr($name, 0, 255, 'UTF-8') : $name;
    }

    private function say(string $line): void
    {
        ($this->say)($line);
    }

    /** A key of a request's own for its Idempotency-Key, a random UUID. */
    private static function newKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4, random
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // the variant RFC 9562 gives
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    /** A document's pages, once XipFax has counted them. */
    private static function pagesOf(DraftDocument $document): string
    {
        $count = $document->getPageCount();
        if ($count === null) {
            return '';
        }
        return $count === 1 ? ', 1 page' : ", $count pages";
    }
}
