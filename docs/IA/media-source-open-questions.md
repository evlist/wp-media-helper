<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Open questions: media sources

These questions are not part of the completed source-configuration slice.
They need explicit decisions and dedicated future slices before implementation.

## WordPress uploads as a source

One possible way to show native WordPress media alongside external files is to
configure the WordPress uploads directory (usually `wp-content/uploads`) as a
source. This might reuse the existing filesystem discovery and source filters,
but it is a hypothesis, not a supported integration yet. The uploads location
must be resolved from WordPress configuration rather than assumed to be the
default path.

A future slice should check how discovered upload files map to existing
WordPress attachments: avoid duplicate registration, distinguish registered
media from files merely present on disk, and preserve the existing attachment
and post relationships. It should also define which operations are safe for
native WordPress media rather than treating an uploads file as an external
virtual attachment by default.

## Editing a source with existing media

Changing a source's name or ID, root directory, path or filter pattern, or
enabled state can leave already registered or post-attached media referring to
its previous identity or path. Existing attachments must not be silently
deleted, detached, or reassigned when source settings are saved.

Before supporting such changes as a complete lifecycle, define how to find
affected attachments, distinguish a rename from a change of filesystem
location, and reconcile provenance metadata and indexed paths. The policy for
attachments that no longer match the source must be explicit, including what
editors can see and whether any migration or confirmation is required. This is
related to the open eligibility-change reconciliation question in
[Media filter model](media-filter-model.md), but also applies when the source
itself is reconfigured.