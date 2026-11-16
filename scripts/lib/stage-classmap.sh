#!/usr/bin/env bash
# Writes vendor/composer/autoload_classmap.php for a staged tree without
# installing any dependency, for the --check-only mode of the two release
# builds. The class-reference gate only reads WPMCP classes out of that map,
# and they all come from the psr-4 "WPMCP\\" => "src/" rule, so a manifest
# holding just that rule yields the same answer as the full composer run.
stage_classmap() {
  local stage="$1"
  printf '{"name":"wpmcp/stage","autoload":{"psr-4":{"WPMCP\\\\":"src/"}}}\n' > "$stage/composer.json"
  rm -f "$stage/composer.lock"
  composer dump-autoload --working-dir="$stage" --optimize --classmap-authoritative --quiet --no-interaction
}
