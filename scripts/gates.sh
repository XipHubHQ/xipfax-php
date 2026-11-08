#!/bin/sh
# The repository's gate, run before every pull request: the SDK as openapi.json generates it, its
# version and its namespace as the settings and composer.json name them, composer.json and
# composer.lock as Composer validates them, and the build, which reads every PHP file of the SDK,
# its specs and its example and loads every class of the SDK by the name its path gives it. The
# specs run once before each release, by scripts/test.sh, not here. Composer and PHP run in their
# images with no network, on what scripts/fetch.sh fetched: a file vendor/ lacks stops the gate,
# named.
set -eu
cd "$(dirname "$0")/.."

sh scripts/generate.sh

# What the generator writes, by the list it wrote now and the one the commit holds, and
# ApiVersion.php: a generated file that differs from the commit, is new to it or is gone from it
# fails the gate.
generated=$({
  cat .openapi-generator/FILES
  git show HEAD:.openapi-generator/FILES 2>/dev/null || true
} | grep -E '^src/.+\.php$' | sort -u)
# $generated is split into its paths on purpose, one a line.
# shellcheck disable=SC2086
changed=$(git status --porcelain --untracked-files=all -- src/ApiVersion.php .openapi-generator $generated)
if [ -n "$changed" ]; then
  echo "The SDK differs from what openapi.json generates: run scripts/generate.sh and commit it." >&2
  echo "$changed" >&2
  exit 1
fi

# The release the generator writes into the user agent, held to the one the settings name.
version=$(sed -n 's/^artifactVersion: //p' openapi-generator.yaml)
agent=$(sed -n 's/^httpUserAgent: //p' openapi-generator.yaml)
if [ -z "$version" ] || [ "$agent" != "xipfax-php/$version" ]; then
  echo "openapi-generator.yaml's httpUserAgent, $agent, isn't xipfax-php/ and its artifactVersion, $version: name the release in both." >&2
  exit 1
fi

# The namespace the generator writes the SDK in, held to the one composer.json loads from src/.
namespace=$(sed -n 's/^invokerPackage: //p' openapi-generator.yaml)
loaded=$(jq -r '.autoload["psr-4"] | to_entries[] | select(.value == "src/") | .key' composer.json)
if [ -z "$namespace" ] || [ "$loaded" != "$namespace\\" ]; then
  printf '%s\n' "openapi-generator.yaml's invokerPackage, $namespace, isn't the namespace composer.json loads from src/, $loaded: name it in both." >&2
  exit 1
fi

sh scripts/docker.sh offline composer composer validate --strict
sh scripts/docker.sh offline php php scripts/build.php
echo "Gates: GREEN"
