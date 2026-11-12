# Upstream tracking

This repository is a fork of [mahirgul/aipbx-plugins](https://github.com/mahirgul/aipbx-plugins).

| | |
|---|---|
| Upstream base (last reviewed) | `8fffabeecc4b1c1c6e2bba3b02733d308e20969a` (2026-11-11, "docs: refresh catalog migration and SMS sending guidance") |
| `plugins/community/` | Upstream as of the base, unchanged |
| `plugins/custom/` | Ours (see [plugins/custom/README.md](plugins/custom/README.md)) |

## Our modified copies of upstream plugins

| Ours | Based on upstream | Upstream version at base |
|------|-------------------|--------------------------|
| `plugins/custom/sms` (1.3.0) | `plugins/community/sms` | 1.2.2 |

## When upstream releases something new

1. Run `scripts/upstream-check.sh`. It lists every upstream commit since the base and, for each
   modified plugin, the upstream changes to merge into ours.
2. Merge the upstream changes we want into `plugins/community/` (as-is) and into our modified copy,
   keeping our changes. Run the plugin's tests.
3. Copy the result to the OwnPBX repository (`plugins/<slug>/`) and release.
4. Update the base commit above.
