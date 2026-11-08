#!/bin/sh
# Makes the SDK from openapi.json, the API's document: scripts/sdk-document.jq writes the document
# it is generated from, openapi-generator 7.17.0 generates the client from that with
# openapi-generator.yaml, and this script writes src/ApiVersion.php from the document's
# info.version. Nothing it writes is edited by hand. It fetches nothing.
set -eu
cd "$(dirname "$0")/.."

generator=$(openapi-generator version)
if [ "$generator" != "7.17.0" ]; then
  echo "scripts/generate.sh takes openapi-generator 7.17.0, and this machine has $generator." >&2
  exit 1
fi

if ! version=$(jq -er '.info.version | select(test("^[0-9]{4}-[0-9]{2}-[0-9]{2}$"))' openapi.json); then
  echo "openapi.json's info.version is not a day, as 2026-11-03 is." >&2
  exit 1
fi

# The SDK's PHP namespace, which openapi-generator.yaml names alone.
if ! namespace=$(sed -n 's/^invokerPackage: //p' openapi-generator.yaml | grep -E -x '[A-Z][A-Za-z0-9]*(\\[A-Z][A-Za-z0-9]*)+'); then
  printf '%s\n' "openapi-generator.yaml's invokerPackage is not one PHP namespace, as XipHub\\XipFax\\Sdk is." >&2
  exit 1
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
jq -f scripts/sdk-document.jq openapi.json > "$work/openapi.json"

# The PHP files the last run wrote go first, so that one the document no longer makes doesn't stay,
# and ApiVersion.php, this script's.
if [ -f .openapi-generator/FILES ]; then
  grep -E '^src/.+\.php$' .openapi-generator/FILES | while IFS= read -r file; do
    rm -f "./$file"
  done
fi
rm -f src/ApiVersion.php

openapi-generator generate -g php -i "$work/openapi.json" -o . -c openapi-generator.yaml

cat > src/ApiVersion.php <<EOF
<?php

// Written by scripts/generate.sh from openapi.json's info.version. Do not edit.

declare(strict_types=1);

namespace $namespace;

/**
 * The version of XipFax's API this SDK is generated from, which every request names in its
 * Xip-Version header.
 */
final class ApiVersion
{
    /** The version, the day the API's document names it by. */
    public const API_VERSION = '$version';

    private function __construct()
    {
    }
}
EOF
