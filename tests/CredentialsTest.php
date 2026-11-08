<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use XipHub\XipFax\Sdk\Tests\Support\Credentials;

/** No credential reaches a spec: tests/bootstrap.php strips each before any spec runs. */
final class CredentialsTest extends TestCase
{
    /** The variables the strip is given, each with its value. */
    private const GIVEN = [
        'XIPFAX_API_KEY' => 'stand-in',
        'XIPFAX_TO' => '+1 202 555 0143',
        'GITHUB_TOKEN' => 'stand-in',
        'PLAIN_SETTING' => 'kept',
    ];

    public function testTheEnvironmentHoldsNoCredential(): void
    {
        self::assertSame([], Credentials::left(), 'the specs run with these in their environment');
    }

    public function testStripTakesAKeyATokenAndTheXipFaxVariablesAndKeepsTheRest(): void
    {
        foreach (self::GIVEN as $name => $value) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        try {
            self::assertSame(['GITHUB_TOKEN', 'XIPFAX_API_KEY', 'XIPFAX_TO'], Credentials::strip());
            foreach (['GITHUB_TOKEN', 'XIPFAX_API_KEY', 'XIPFAX_TO'] as $name) {
                self::assertFalse(getenv($name), "$name is still in the environment");
                self::assertArrayNotHasKey($name, $_ENV, "$name is still in \$_ENV");
                self::assertArrayNotHasKey($name, $_SERVER, "$name is still in \$_SERVER");
            }
            self::assertSame('kept', getenv('PLAIN_SETTING'), 'the strip took PLAIN_SETTING too');
            self::assertSame('kept', $_ENV['PLAIN_SETTING'] ?? null, 'the strip took PLAIN_SETTING from $_ENV');
            self::assertSame('kept', $_SERVER['PLAIN_SETTING'] ?? null, 'the strip took PLAIN_SETTING from $_SERVER');
        } finally {
            foreach (array_keys(self::GIVEN) as $name) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
    }
}
