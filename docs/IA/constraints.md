<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->


# Constraints and Working Rules

## Languages

- Interation with IA agents may be in any language
- Comments and documentation must stay in English. 

## Internationalisation

- Every user-facing string must be translatable. No literal user-facing text may
  reach the browser untranslated.
- PHP strings must use the WordPress translation functions with the
  `wp-media-helper` text domain, combined with the matching escaping function
  (`esc_html__()`, `esc_attr__()`, and so on) rather than escaping a translated
  value separately.
- JavaScript strings must be translated with the `@wordpress/i18n` package and
  shipped as JSON translation files. Scripts therefore must be registered as
  real asset files declaring the `wp-i18n` dependency and calling
  `wp_set_script_translations()`; inline scripts cannot be localised this way.
- Strings assembled from fragments are not acceptable. Use placeholders and
  `sprintf()` so translators receive complete sentences.

## Product and code constraints

- Full WordPress standards compliance is required.
- Full REUSE compliance with `GPL-3.0-or-later` is required.
- Product logic should not assume shell execution at runtime when a PHP
  integration exists.
- External media support must remain optional and support multiple configured
  roots, each with its own path pattern and optional filename filter pattern.
- External media discovery should use a persistent local index and targeted
  refreshes rather than scanning every configured root on each page load.
- Directory modification times may be used as invalidation hints, but must not
  be treated as authoritative filesystem change notifications.
- The UI must prevent selection and confirmation while relevant external media
  data is being refreshed, while preserving visible results and current user
  selections.
- A low-load scheduled refresh may maintain the index proactively, but the
  request-time consistency check remains authoritative.

## Security model for external source configuration

- The external source settings page requires the `manage_options` capability.
  Anyone who can reach it already has full site control, so it is treated as a
  trusted-admin surface, not an untrusted-input boundary.
- `root` is not restricted to an allow-listed filesystem scope: an admin can
  point it at any absolute, existing, readable directory. Because of this,
  rejecting `../` or absolute-path injection in `path_pattern` would not stop
  a malicious admin (who could set `root` itself to a sensitive path) and
  cannot be reached by a non-admin (blocked by the capability check and
  nonce). Such a check was tried and removed for giving a false sense of
  security; see the project history for details.
- An admin-editable allow-list of permitted roots would not fix this either:
  it would be stored and modified through the same `manage_options` surface,
  so it moves the trust boundary nowhere. A scope restriction only becomes
  meaningful if it is defined outside admin reach (for example a constant or
  a filter set by the site owner in code), which is a larger architectural
  feature and is not planned unless explicitly requested.

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

