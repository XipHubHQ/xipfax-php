<?php

// Loaded by PHPUnit before any spec, as phpunit.xml.dist names it: the SDK's classes and the specs',
// then every variable shaped like a credential stripped from the environment, so that no spec has a
// key to reach XipFax with. CredentialsTest asserts that none is left.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\XipHub\XipFax\Sdk\Tests\Support\Credentials::strip();
