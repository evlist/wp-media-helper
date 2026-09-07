# External media fixtures

The Codespace includes a repeatable external-media tree for testing the plugin against the embedded WordPress installation. The fixture lives under `.devcontainer/var/`, which is ignored by Git.

This location is intentional: `.devcontainer/var/` is both ignored by this repository and protected by the graft convention. Runtime fixture data therefore survives graft updates without becoming part of the repository history.

## Create a fixture

From the repository root:

```bash
.devcontainer/bin/media-fixture.local.sh create --mode writable
```

The command creates:

```text
.devcontainer/var/external-media-fixture/
└── 2026/
    └── 08/
        ├── 20260810-morning.png
        ├── 20260810-summit.png
        ├── 20260810-route.gpx
        ├── 20260810-not-an-image.txt
        ├── 20260811-morning.png
        ├── 20260811-summit.png
        └── 20260811-route.gpx
```

Use these values in the WP Media Helper source settings:

- Root directory: the absolute path printed by the command.
- Path pattern: `{date:Y}/{date:m}`.
- Filter pattern: `{date:Ymd}`.

For the sample date `2026-08-10`, the resolved directory is `2026/08` and the date filter selects files containing `20260810`.

## Test permissions

Create a web-readable read-only source:

```bash
.devcontainer/bin/media-fixture.local.sh reset --mode readonly
```

Switch an existing fixture to writable mode:

```bash
.devcontainer/bin/media-fixture.local.sh permissions writable
```

Switch it back to read-only mode:

```bash
.devcontainer/bin/media-fixture.local.sh permissions readonly
```

Read-only mode uses readable directories and files (`0555` / `0444`). Writable mode uses group-writable permissions (`0775` / `0664`) and assigns the `www-data` group when available.

## Simulate changes

Add a new pair of PNG/GPX files for a date:

```bash
.devcontainer/bin/media-fixture.local.sh add 2026-08-10 late-evening
```

Remove that pair:

```bash
.devcontainer/bin/media-fixture.local.sh remove 2026-08-10 late-evening
```

Update the directory mtime without adding a file, which is useful for testing stale-index detection:

```bash
.devcontainer/bin/media-fixture.local.sh touch 2026-08-10
```

## Cleanup

Remove the complete fixture:

```bash
.devcontainer/bin/media-fixture.local.sh clean
```

The fixture is deliberately separate from the WordPress document root and the plugin source. It can therefore be recreated, made read-only, mutated, and deleted without changing tracked project files.
