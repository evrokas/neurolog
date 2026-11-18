#!/bin/bash
# Run by fw/bin/update.sh after its schema/content steps (opt-in hook). Idempotent steps only.
# 1. Controlled vocabularies: vocab/ -> nl_codes (bin/load_vocab.php; never deletes, retires instead).
set -e
cd "$(dirname "$0")/.."
php bin/load_vocab.php
