<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests\Support;

/**
 * Keeps every credential out of the specs. tests/bootstrap.php calls strip() before any spec runs:
 * anything in the environment shaped like a credential, or named for XipFax, goes. The day a real
 * key sits in a developer's shell, a spec that could reach the API still has nothing to reach it
 * with, and the specs keep saying so.
 */
final class Credentials
{
    /** A variable that is a secret in itself, in any case, its $ the name's very end. */
    private const SECRET = '/(TOKEN|SECRET|PASSWORD|CREDENTIAL|AUTH|APIKEY|API_KEY|_KEY$|_KEYS$|COOKIE|PRIVATE)/iD';

    /** The variables XipFax's own examples read, such as XIPFAX_API_KEY. */
    private const XIPFAX = '/^XIPFAX_/i';

    /** Whether a spec's process has no business carrying the variable name. */
    public static function shaped(string $name): bool
    {
        return preg_match(self::SECRET, $name) === 1 || preg_match(self::XIPFAX, $name) === 1;
    }

    /**
     * The name of each such variable in the process's environment, as getenv(), $_ENV and $_SERVER
     * give it, sorted.
     *
     * @return list<string>
     */
    public static function left(): array
    {
        $names = [...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)];
        $left = [];
        foreach ($names as $name) {
            if (is_string($name) && self::shaped($name)) {
                $left[$name] = true;
            }
        }
        $left = array_keys($left);
        sort($left, SORT_STRING);
        return $left;
    }

    /**
     * Removes each such variable from the process's environment, answering the names it removed,
     * sorted.
     *
     * @return list<string>
     */
    public static function strip(): array
    {
        $removed = self::left();
        foreach ($removed as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        return $removed;
    }
}
