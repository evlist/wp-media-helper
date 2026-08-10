#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
#
# SPDX-License-Identifier: GPL-3.0-or-later OR MIT

# Install git-lfs for versioning binary test assets

log() { printf "[bootstrap:git-lfs] %s\n" "$*"; }

if command -v git-lfs >/dev/null 2>&1; then
  log "git-lfs already installed: $(git-lfs version)"
  exit 0
fi

log "Installing git-lfs..."
curl -fsSL https://packagecloud.io/install/repositories/github/git-lfs/script.deb.sh | bash
apt-get install -y git-lfs
git lfs install --system
log "git-lfs installed: $(git-lfs version)"
