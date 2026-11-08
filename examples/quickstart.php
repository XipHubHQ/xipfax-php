<?php

// Runs XipFax's quickstart in your terminal, from a clone of this repository, after composer install:
//
//     XIPFAX_API_KEY=xipfax_key_live_… php examples/quickstart.php
//
// That takes the free steps alone. To send a fax too, real and charged, name a fax number of yours
// and a public link to a PDF:
//
//     XIPFAX_TO='+1 202 555 0143' XIPFAX_PDF_URL=https://example.com/letter.pdf XIPFAX_API_KEY=… php examples/quickstart.php

declare(strict_types=1);

use XipHub\XipFax\Sdk\ApiException;
use XipHub\XipFax\Sdk\Examples\QuickstartSteps;
use XipHub\XipFax\Sdk\Model\ApiError;
use XipHub\XipFax\Sdk\XipFax;

require __DIR__ . '/../vendor/autoload.php';

try {
    $xipfax = new XipFax((string) getenv('XIPFAX_API_KEY'));
} catch (InvalidArgumentException) {
    fwrite(STDERR, "Set XIPFAX_API_KEY to a key you made in the developer console: https://developer.xipfax.com/console/\n");
    exit(1);
}
$to = (string) getenv('XIPFAX_TO');
$pdfUrl = (string) getenv('XIPFAX_PDF_URL');

$steps = new QuickstartSteps(
    $xipfax,
    $to,
    $pdfUrl,
    say: static function (string $line): void {
        echo $line, "\n";
    },
    ask: static function (string $question): string {
        echo $question;
        // A terminal that closes answers what was typed before it did, which sends only if that was send.
        $line = fgets(STDIN);
        return $line === false ? '' : $line;
    },
    pause: static function (int $seconds): void {
        sleep($seconds);
    },
);

try {
    $steps->run();
} catch (ApiException $refused) {
    $refusal = $refused->getResponseObject();
    if ($refusal instanceof ApiError) {
        fwrite(STDERR, "XipFax refused it, {$refusal->getError()->getCode()}: {$refusal->getError()->getMessage()}\n");
    } else {
        fwrite(STDERR, $refused->getMessage() . "\n");
    }
    exit(1);
} catch (Throwable $failed) {
    fwrite(STDERR, $failed->getMessage() . "\n");
    exit(1);
}
if ($to === '' || $pdfUrl === '') {
    echo "Those steps cost nothing. To send a fax too, run it again with XIPFAX_TO, a fax number of yours, and XIPFAX_PDF_URL, a public link to a PDF.\n";
}
