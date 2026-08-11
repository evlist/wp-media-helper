<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->


# Constraints and Working Rules

## Languages

- Interation with IA agents may be in any language
- Comments and documentation must stay in English. 

## Product and code constraints

- Full WordPress standards compliance is required.
- Full REUSE compliance with `GPL-3.0-or-later` is required.
- Product logic should not assume shell execution at runtime when a PHP
  integration exists.
- External media support must remain optional and support multiple configured
  roots, each with its own path pattern and optional filename filter pattern.

## Repository and environment constraints

- Managed `.devcontainer/` graft files should not be edited directly unless they
  are local override files, cf https://github.com/evlist/codespaces-grafting .
- Runtime code should stay under the PSR-4 structure rooted at
  `plugin/includes/{{Namespace}}/`.
- Configuration should live in the plugin settings page rather than in ad hoc
  runtime constants or shell-dependent setup.

## Delivery discipline

This repository should continue to follow a small-slice XP workflow:

- tiny vertical slices,
- test-first when practical,
- focused validation before widening scope,
- behavior-oriented tests,
- deletion of stale scaffolding rather than speculative accumulation.

