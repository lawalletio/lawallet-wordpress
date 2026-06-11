#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

./bin/setup-local.sh

cd e2e
npm install
npx playwright install chromium
npx playwright test
