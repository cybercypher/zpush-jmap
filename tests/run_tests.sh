#!/usr/bin/env bash
#
# Z-Push JMAP Integration Test Runner
#
# Usage:
#   ./tests/run_tests.sh                     # non-destructive tests
#   TEST_DESTRUCTIVE=1 ./tests/run_tests.sh  # includes move/trash tests
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"

# Source .env if present (strip \r from Windows line endings)
if [[ -f "$ENV_FILE" ]]; then
    echo "Loading $ENV_FILE"
    set -a
    source <(sed 's/\r$//' "$ENV_FILE")
    set +a
fi

# Validate required vars
: "${STALWART_URL:?Set STALWART_URL in tests/.env or environment}"
: "${TEST_USER:?Set TEST_USER in tests/.env or environment}"
: "${TEST_PASSWORD:?Set TEST_PASSWORD in tests/.env or environment}"

IMAGE="local/zpush:latest"

# Check image exists
if ! docker image inspect "$IMAGE" &>/dev/null; then
    echo "Docker image $IMAGE not found. Build it first:"
    echo "  docker compose -f nginx/docker-compose.yml build"
    exit 1
fi

echo "Running tests against $STALWART_URL as $TEST_USER"
echo ""

docker run --rm \
    --entrypoint php \
    -e STALWART_URL="$STALWART_URL" \
    -e TEST_USER="$TEST_USER" \
    -e TEST_PASSWORD="$TEST_PASSWORD" \
    -e TEST_DESTRUCTIVE="${TEST_DESTRUCTIVE:-0}" \
    -v "$SCRIPT_DIR":/tests:ro \
    "$IMAGE" /tests/test_jmap.php

exit_code=$?

if [[ $exit_code -eq 0 ]]; then
    echo ""
    echo "All tests passed."
else
    echo ""
    echo "Some tests failed (exit code $exit_code)."
fi

exit $exit_code
