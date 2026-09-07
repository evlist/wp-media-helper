#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
#
# SPDX-License-Identifier: GPL-3.0-or-later OR MIT

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
DEFAULT_ROOT="${REPO_ROOT}/.devcontainer/var/external-media-fixture"
FIXTURE_ROOT="${MEDIA_FIXTURE_ROOT:-$DEFAULT_ROOT}"

log() {
  printf '[media-fixture] %s\n' "$*"
}

die() {
  printf '[media-fixture:ERROR] %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage:
  media-fixture.local.sh create [--mode readonly|writable] [--root PATH]
  media-fixture.local.sh reset [--mode readonly|writable] [--root PATH]
  media-fixture.local.sh add DATE NAME [--root PATH]
  media-fixture.local.sh remove DATE NAME [--root PATH]
  media-fixture.local.sh touch DATE [--root PATH]
  media-fixture.local.sh permissions readonly|writable [--root PATH]
  media-fixture.local.sh path [--root PATH]
  media-fixture.local.sh clean [--root PATH]

Commands:
  create       Create the fixture when it does not exist.
  reset        Remove and recreate the fixture with sample files.
  add          Add a PNG sample and a GPX file below DATE (YYYY-MM-DD).
  remove       Remove a named sample from DATE.
  touch        Update the target date directory mtime to simulate a change.
  permissions  Switch the fixture between web-readable/read-only and writable.
  path         Print the absolute fixture path.
  clean        Remove the fixture entirely.

The default fixture path is .devcontainer/var/external-media-fixture.
It can also be overridden with MEDIA_FIXTURE_ROOT.
USAGE
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

run_privileged() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    die "This operation needs root privileges or sudo: $*"
  fi
}

parse_root() {
  local args=()
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --root)
        [ "$#" -ge 2 ] || die "--root requires a path."
        FIXTURE_ROOT="$2"
        shift 2
        ;;
      *)
        args+=("$1")
        shift
        ;;
    esac
  done
  PARSED_ARGS=("${args[@]}")
}

validate_date() {
  [[ "$1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || die "Date must use YYYY-MM-DD format: $1"
}

validate_name() {
  [[ "$1" =~ ^[A-Za-z0-9._-]+$ ]] || die "Name may contain only letters, numbers, dots, underscores, and hyphens: $1"
}

parse_mode() {
  case "$1" in
    readonly|writable) FIXTURE_MODE="$1" ;;
    *) die "Mode must be readonly or writable: $1" ;;
  esac
}

set_permissions() {
  local mode="$1"
  [ -d "$FIXTURE_ROOT" ] || die "Fixture does not exist: $FIXTURE_ROOT"

  if [ "$mode" = readonly ]; then
    run_privileged find "$FIXTURE_ROOT" -type d -exec chmod 0555 {} +
    run_privileged find "$FIXTURE_ROOT" -type f -exec chmod 0444 {} +
  else
    run_privileged find "$FIXTURE_ROOT" -type d -exec chmod 0775 {} +
    run_privileged find "$FIXTURE_ROOT" -type f -exec chmod 0664 {} +
    if getent group www-data >/dev/null 2>&1; then
      run_privileged chgrp -R www-data "$FIXTURE_ROOT"
    fi
  fi

  log "Permissions set to ${mode}: ${FIXTURE_ROOT}"
}

remove_fixture() {
  if [ -e "$FIXTURE_ROOT" ]; then
    run_privileged rm -rf "$FIXTURE_ROOT"
  fi
}

write_png() {
  local target="$1"
  printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' \
    | base64 --decode > "$target"
}

write_gpx() {
  local target="$1"
  cat > "$target" <<'GPX'
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="wp-media-helper-fixture" xmlns="http://www.topografix.com/GPX/1/1">
  <trk><name>Fixture route</name><trkseg>
    <trkpt lat="45.1667" lon="5.7167"><ele>250</ele></trkpt>
    <trkpt lat="45.1700" lon="5.7200"><ele>275</ele></trkpt>
  </trkseg></trk>
</gpx>
GPX
}

create_sample_day() {
  local date="$1"
  local year="${date:0:4}"
  local month="${date:5:2}"
  local day="${date:8:2}"
  local directory="${FIXTURE_ROOT}/${year}/${month}"
  local stem="${year}${month}${day}"

  mkdir -p "$directory"
  write_png "${directory}/${stem}-morning.png"
  write_png "${directory}/${stem}-summit.png"
  write_gpx "${directory}/${stem}-route.gpx"
}

create_fixture() {
  local mode="$1"
  mkdir -p "$FIXTURE_ROOT"
  create_sample_day "2026-08-10"
  create_sample_day "2026-08-11"
  printf 'This file is intentionally not an image.\n' > "${FIXTURE_ROOT}/2026/08/20260810-not-an-image.txt"
  set_permissions "$mode"
  log "Fixture ready. Configure this root in WP Media Helper: ${FIXTURE_ROOT}"
  log "Path pattern: {date:Y}/{date:m}"
  log "Filter pattern: {date:Ymd}"
}

add_sample() {
  local date="$1"
  local name="$2"
  validate_date "$date"
  validate_name "$name"
  local directory="${FIXTURE_ROOT}/${date:0:4}/${date:5:2}"
  local stem="${date:0:4}${date:5:2}${date:8:2}"
  mkdir -p "$directory"
  write_png "${directory}/${stem}-${name}.png"
  write_gpx "${directory}/${stem}-${name}.gpx"
  log "Added ${name} samples for ${date}."
}

remove_sample() {
  local date="$1"
  local name="$2"
  validate_date "$date"
  validate_name "$name"
  local directory="${FIXTURE_ROOT}/${date:0:4}/${date:5:2}"
  local stem="${date:0:4}${date:5:2}${date:8:2}"
  rm -f "${directory}/${stem}-${name}.png" "${directory}/${stem}-${name}.gpx"
  log "Removed ${name} samples for ${date}."
}

parse_root "$@"
set -- "${PARSED_ARGS[@]}"

[ "$#" -gt 0 ] || { usage; exit 1; }
COMMAND="$1"
shift

case "$COMMAND" in
  create|reset)
    MODE=writable
    if [ "$#" -gt 0 ] && [ "$1" = --mode ]; then
      [ "$#" -ge 2 ] || die "--mode requires readonly or writable."
      parse_mode "$2"
      MODE="$FIXTURE_MODE"
      shift 2
    fi
    [ "$#" -eq 0 ] || die "Unexpected arguments for $COMMAND."
    require_command base64
    if [ "$COMMAND" = reset ]; then
      remove_fixture
    elif [ -d "$FIXTURE_ROOT" ] && [ -n "$(find "$FIXTURE_ROOT" -mindepth 1 -print -quit)" ]; then
      die "Fixture already contains files. Use reset to recreate it."
    fi
    create_fixture "$MODE"
    ;;
  add)
    [ "$#" -ge 2 ] || die "add requires DATE and NAME."
    require_command base64
    add_sample "$1" "$2"
    ;;
  remove)
    [ "$#" -ge 2 ] || die "remove requires DATE and NAME."
    remove_sample "$1" "$2"
    ;;
  touch)
    [ "$#" -ge 1 ] || die "touch requires DATE."
    validate_date "$1"
    directory="${FIXTURE_ROOT}/${1:0:4}/${1:5:2}"
    [ -d "$directory" ] || die "Date directory does not exist: $directory"
    touch "$directory"
    log "Updated directory mtime: $directory"
    ;;
  permissions)
    [ "$#" -ge 1 ] || die "permissions requires readonly or writable."
    parse_mode "$1"
    set_permissions "$FIXTURE_MODE"
    ;;
  path)
    [ "$#" -eq 0 ] || die "Unexpected arguments for path."
    printf '%s\n' "$FIXTURE_ROOT"
    ;;
  clean)
    [ "$#" -eq 0 ] || die "Unexpected arguments for clean."
    remove_fixture
    log "Removed fixture: $FIXTURE_ROOT"
    ;;
  help|-h|--help)
    usage
    ;;
  *)
    usage >&2
    die "Unknown command: $COMMAND"
    ;;
esac
