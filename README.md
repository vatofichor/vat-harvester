# vat-harvester

```
# Copyright (c) 2026:
# vatofichor - Sebastian Mass     [>_<]
# & Assisted By Gemini Antigravity /|\
```

---

Workspace file aggregator, staging engine, and antipattern ejector CLI tool.

---

## Quick Command Reference

Run via `harvester.bat <subcommand>` (or `php harvester.php <subcommand>`):

```cmd
harvester status              Display current staging and repository summary
harvester pull                Scan target workspace folders and stage matched items
harvester pull --purge        Clear staging ledgers and re-scan target workspace
harvester eject               Dry-run scan for antipattern candidates
harvester eject --confirm     Move antipattern candidate files to unspec/
harvester commit --id=<ID>    Promote a specific staged item by Hash ID
harvester commit --all        Promote all staged items to repository storage
harvester purge               Clear temporary staging buffer
harvester digest              Generate markdown digest report and archive
```

---

## Batch File Shortcuts

Standard batch shortcuts for quick execution:

| Script | Action |
| :--- | :--- |
| `pull_specs.bat` | Runs `harvester pull` |
| `commit_staged.bat <ID>` | Runs `harvester commit --id=<ID>` |
| `commit_all.bat` | Runs `harvester commit --all` |
| `digest_cycle.bat` | Runs `harvester digest` |
| `dev/admin_scripts/eject_unspecs.bat` | Runs `harvester eject` |

*Note: All batch files pause automatically so CLI output remains visible.*

---

## Configuration (`harvester.config.json`)

Set your target execution root and target subdirectories in `harvester.config.json`:

```json
{
  "target_root": "./dev/test_repo",
  "target_subdirs": [
    "dev/specs",
    ".dev/specs"
  ]
}
```

---

## Coming Up Next (v1.2.0 Roadmap)

- **Linux & POSIX Shell Support**: POSIX-compliant shell wrappers (`harvester.sh`, `pull_specs.sh`, etc.) and cross-platform path normalization for Linux/macOS environments.
- **Environment & PHP Binary Checker**: Pre-flight environment verifier checking PHP version compliance (PHP 8+), `ZipArchive` extension availability, and graceful fallback handling if local PHP binaries are missing from system PATH.

