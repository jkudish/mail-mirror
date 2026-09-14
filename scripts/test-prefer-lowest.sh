#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

exec bash "$root/scripts/test-compatibility.sh" 13 lowest
