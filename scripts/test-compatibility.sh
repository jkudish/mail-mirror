#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
laravel_major="${1:-}"
resolution="${2:-}"

case "$laravel_major" in
  12) testbench_major=10; pest_constraint='^4.7.8'; pest_plugin_constraint='^4.0' ;;
  13) testbench_major=11; pest_constraint='^5.1.4'; pest_plugin_constraint='^5.0' ;;
  *) printf 'Unsupported Laravel major: %s\n' "$laravel_major" >&2; exit 1 ;;
esac

case "$resolution" in
  stable) update_flags=() ;;
  lowest) update_flags=(--prefer-lowest) ;;
  *) printf 'Unsupported dependency resolution: %s\n' "$resolution" >&2; exit 1 ;;
esac

php_command=(php)
composer_command=(composer)
host_home="${PR_CHECK_HOST_HOME:-$HOME}"
if [ "$(uname -s)" = Darwin ] && [ -x "$host_home/Library/Application Support/Herd/bin/php85" ]; then
  php_command=("$host_home/Library/Application Support/Herd/bin/php85")
  composer_command=("${php_command[@]}" "$host_home/Library/Application Support/Herd/bin/composer")
fi

git -C "$root" checkout-index --all --prefix="$work/"
"${composer_command[@]}" --working-dir="$work" require \
  "illuminate/contracts:${laravel_major}.*" "illuminate/support:${laravel_major}.*" \
  --no-interaction --no-update
"${composer_command[@]}" --working-dir="$work" require --dev \
  "orchestra/testbench:${testbench_major}.*" "pestphp/pest:${pest_constraint}" \
  "pestphp/pest-plugin-arch:${pest_plugin_constraint}" \
  "pestphp/pest-plugin-laravel:${pest_plugin_constraint}" \
  --no-interaction --no-update
"${composer_command[@]}" --working-dir="$work" update "${update_flags[@]}" \
  --prefer-dist --no-interaction --no-progress
"${composer_command[@]}" --working-dir="$work" validate --strict
"${composer_command[@]}" --working-dir="$work" audit --locked --no-interaction
"${php_command[@]}" "$work/vendor/bin/phpstan" analyse --memory-limit=1G --configuration="$work/phpstan.neon"
"${php_command[@]}" "$work/vendor/bin/pest" --ci --fail-on-deprecation \
  --display-deprecations --configuration="$work/phpunit.xml"

printf 'Laravel %s %s dependency suite passed.\n' "$laravel_major" "$resolution"
