#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
#
# SPDX-License-Identifier: GPL-3.0-or-later OR MIT

# Avoid Apache's DirectorySlash redirect leaking localhost before WordPress and
# the Codespaces mu-plugin have a chance to normalize URLs.

set +u

if ! declare -F log >/dev/null 2>&1; then
  log() { printf '[wp-admin-slash] %s\n' "$*"; }
fi

if [ -n "${CODESPACE_NAME:-}" ]; then
  PUBLIC_URL="https://${CODESPACE_NAME}-80.app.github.dev"
else
  PUBLIC_URL="http://localhost"
fi

DOCROOT="${DOCROOT:-/var/www/html}"
HTACCESS="${DOCROOT}/.htaccess"

log "Installing wp-admin slash redirect guard for ${PUBLIC_URL}..."

sudo a2enmod rewrite >/dev/null 2>&1 || true
sudo a2disconf wp-admin-slash-redirect >/dev/null 2>&1 || true

# Ensure WordPress's own rewrite block exists; wp-cli cannot regenerate it
# outside an HTTP request context, so seed it here if the file is missing.
if [ ! -f "$HTACCESS" ] || ! grep -q 'BEGIN WordPress' "$HTACCESS"; then
  log "Seeding default WordPress rewrite block in ${HTACCESS}..."
  {
    printf '%s\n' '# BEGIN WordPress'
    printf '%s\n' '<IfModule mod_rewrite.c>'
    printf '%s\n' 'RewriteEngine On'
    printf '%s\n' 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]'
    printf '%s\n' 'RewriteBase /'
    printf '%s\n' 'RewriteRule ^index\.php$ - [L]'
    printf '%s\n' 'RewriteCond %{REQUEST_FILENAME} !-f'
    printf '%s\n' 'RewriteCond %{REQUEST_FILENAME} !-d'
    printf '%s\n' 'RewriteRule . /index.php [L]'
    printf '%s\n' '</IfModule>'
    printf '%s\n' '# END WordPress'
  } >> "$HTACCESS"
fi

# Merge our guard block in place instead of overwriting the rest of the file.
GUARD_BLOCK="$(cat <<GUARD
# BEGIN wp-admin Codespaces guard
RewriteEngine On
RewriteRule ^wp-admin$ ${PUBLIC_URL}/wp-admin/ [R=302,L,NE]
# END wp-admin Codespaces guard
GUARD
)"

if grep -q 'BEGIN wp-admin Codespaces guard' "$HTACCESS"; then
  sed -i '/# BEGIN wp-admin Codespaces guard/,/# END wp-admin Codespaces guard/d' "$HTACCESS"
fi

printf '%s\n\n%s' "$GUARD_BLOCK" "$(cat "$HTACCESS")" > "${HTACCESS}.tmp" && mv "${HTACCESS}.tmp" "$HTACCESS"

sudo apache2ctl -t >/dev/null 2>&1 && sudo service apache2 reload >/dev/null 2>&1 || true

set -u
