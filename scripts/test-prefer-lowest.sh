#!/usr/bin/env bash
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

composer_command=(composer)
host_home="${PR_CHECK_HOST_HOME:-$HOME}"
if [ "$(uname -s)" = Darwin ] && [ -x "$host_home/Library/Application Support/Herd/bin/php85" ]; then
  composer_command=("$host_home/Library/Application Support/Herd/bin/php85" "$host_home/Library/Application Support/Herd/bin/composer")
fi

git -C "$root" checkout-index --all --prefix="$work/"
"${composer_command[@]}" --working-dir="$work" require 'illuminate/contracts:13.*' 'illuminate/support:13.*' --no-interaction --no-update
"${composer_command[@]}" --working-dir="$work" require --dev 'orchestra/testbench:11.*' --no-interaction --no-update
"${composer_command[@]}" --working-dir="$work" update --prefer-lowest --prefer-dist --no-interaction --no-progress
"${composer_command[@]}" --working-dir="$work" audit --locked --no-interaction
"${composer_command[@]}" --working-dir="$work" test
