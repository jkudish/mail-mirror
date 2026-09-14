#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
laravel_major="${1:-13}"
database="$work/consumer.sqlite"

touch "$database"
unset DB_URL
export DB_CONNECTION=sqlite
export DB_DATABASE="$database"

case "$laravel_major" in
  12|13) ;;
  *) printf 'Unsupported Laravel major: %s\n' "$laravel_major" >&2; exit 1 ;;
esac

php_command=(php)
composer_command=(composer)
if [ "$(uname -s)" = Darwin ] && [ -x "$HOME/Library/Application Support/Herd/bin/php85" ]; then
  php_command=("$HOME/Library/Application Support/Herd/bin/php85")
  composer_command=("${php_command[@]}" "$(command -v composer)")
fi

"${composer_command[@]}" create-project "laravel/laravel:^${laravel_major}.0" "$work/consumer" \
  --no-interaction --prefer-dist --no-progress
"${composer_command[@]}" --working-dir="$work/consumer" config repositories.mail-mirror \
  "{\"type\":\"path\",\"url\":\"$root\",\"options\":{\"symlink\":false}}"
"${composer_command[@]}" --working-dir="$work/consumer" require jkudish/mail-mirror:@dev \
  --no-interaction --prefer-dist --no-progress
"${php_command[@]}" "$work/consumer/artisan" package:discover --ansi
"${php_command[@]}" "$work/consumer/artisan" migrate --force --ansi
"${php_command[@]}" -r \
  "chdir('$work/consumer'); require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); exit(\$app->getProvider(Jkudish\\MailMirror\\MailMirrorServiceProvider::class) ? 0 : 1);"
"${php_command[@]}" "$root/tests/Fixtures/consumer-smoke.php" "$work/consumer"

printf 'Clean Laravel %s consumer install, migration, and synthetic import passed.\n' "$laravel_major"
