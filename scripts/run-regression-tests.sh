#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
for test_file in tests/*-*.php; do
  [ -f "$test_file" ] || continue
  php "$test_file"
done
for test_file in tests/*-*.js; do
  [ -f "$test_file" ] || continue
  node "$test_file"
done
