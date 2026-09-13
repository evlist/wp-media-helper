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

if [ -f "$HTACCESS" ] && ! grep -q 'BEGIN wp-admin Codespaces guard' "$HTACCESS"; then
  cp "$HTACCESS" "${HTACCESS}.bak.$(date +%Y%m%d%H%M%S)"
fi

cat > "$HTACCESS" <<HTACCESS
# BEGIN wp-admin Codespaces guard
RewriteEngine On
RewriteRule ^wp-admin$ ${PUBLIC_URL}/wp-admin/ [R=302,L,NE]
# END wp-admin Codespaces guard
HTACCESS

sudo apache2ctl -t >/dev/null 2>&1 && sudo service apache2 reload >/dev/null 2>&1 || true

set -u
