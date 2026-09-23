#!/bin/sh
set -eu
. "$(dirname "$0")/apple-container-stack.sh"
exec sh "$ROOT_DIR/scripts/apple-container-wordpress.sh" reset
