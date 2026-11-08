#!/bin/sh
# Fetches what the SDK's build and specs need and this machine lacks: PHP's image and Composer's,
# each by the digest scripts/docker.sh names, and the packages composer.json requires, from
# Packagist, into vendor/, at the versions composer.lock holds. With no composer.lock, Composer takes
# the latest versions composer.json allows and writes composer.lock, which the repository keeps. It
# runs no spec, so that the gate and scripts/test.sh run offline after it. Run it again whenever
# composer.json or composer.lock changes.
set -eu
cd "$(dirname "$0")/.."

sh scripts/docker.sh fetch php php --version
sh scripts/docker.sh fetch composer composer install --no-interaction --no-progress
