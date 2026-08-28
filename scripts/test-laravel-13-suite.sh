#!/usr/bin/env bash
set -euo pipefail

php_command=(php)
composer_command=(composer)
host_home="${PR_CHECK_HOST_HOME:-$HOME}"
if [ "$(uname -s)" = Darwin ] && [ -x "$host_home/Library/Application Support/Herd/bin/php85" ]; then
  php_command=("$host_home/Library/Application Support/Herd/bin/php85")
  composer_command=("${php_command[@]}" "$host_home/Library/Application Support/Herd/bin/composer")
fi

"${composer_command[@]}" show laravel/framework --format=json |
  "${php_command[@]}" -r '$data=json_decode(stream_get_contents(STDIN), true); exit(str_starts_with($data["versions"][0], "v13.") ? 0 : 1);'
"${composer_command[@]}" test
