#!/bin/bash
# Usage: bin/run_tests.sh [--static-only]
cd "$(dirname "$0")/.." && php tests/run_all.php "$@"
