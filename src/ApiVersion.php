<?php

// Written by scripts/generate.sh from openapi.json's info.version. Do not edit.

declare(strict_types=1);

namespace XipHub\XipFax\Sdk;

/**
 * The version of XipFax's API this SDK is generated from, which every request names in its
 * Xip-Version header.
 */
final class ApiVersion
{
    /** The version, the day the API's document names it by. */
    public const API_VERSION = '2026-11-03';

    private function __construct()
    {
    }
}
