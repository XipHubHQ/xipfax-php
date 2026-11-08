#!/bin/sh
# The SDK's specs, run once before each release, as the TypeScript SDK's are: PHPUnit runs every
# spec under tests/ in PHP's image, with no network, on what scripts/fetch.sh fetched. The container
# holds none of the variables of the shell that runs this, so no credential in it reaches a spec, and
# tests/bootstrap.php strips any variable shaped like one before a spec loads, which a spec asserts.
# No spec reaches XipFax: each is answered by a stand-in in its own process.
set -eu
cd "$(dirname "$0")/.."

sh scripts/docker.sh offline php php vendor/bin/phpunit
