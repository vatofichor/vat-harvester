# Technical Specification: Item Taxonomy & Pattern Filter States

```
# Copyright (c) 2026:
# vatofichor - Sebastian Mass     [>_<]
# & Assisted By Gemini Antigravity /|\
```

---

## 1. Architectural Philosophy: Pattern Inheritance on Directory Items

When `vat-harvester` crawls the source filesystem via `WorkspaceScanner`, discovered directory items (`diritems`) possess no intrinsic role until they are evaluated against the configuration rule matrix. 

The engine uses a deterministic **Pattern Inheritance Pipeline** implemented in `RuleClassifier`:
```
Discovered DirItem (File Path)
             │
             ▼
   [ antipattern_regex ] ──(Matches)───────► STATE_EXITM  ('exitm')
             │ (No match)
             ▼
   [ primary_spec_regex ] ─(Matches)───────► STATE_IMITM  ('imitm')
             │ (No match)
             ▼
      (Default State) ─────────────────────► STATE_NNITM  ('nnitm')
```

Custom pattern templates (such as those in [`dev/templates/`](file:///d:/Dev/vat-harvester/dev/templates)) are directly inherited by the engine to control which files become harvesting targets (`imitm`), quarantine candidates (`exitm`), or passive bystanders (`nnitm`).

---

## 2. Core Taxonomy State Matrix

| State Key | Constant Symbol | Taxonomy Name | Evaluation Trigger | Lifecycle Destination |
| :--- | :--- | :--- | :--- | :--- |
| **`exitm`** | `RuleClassifier::STATE_EXITM` | **Exit Item / Ejection Candidate** | Filename matches `rules.antipattern_regex` | Excluded from `pull`. Quarantined to `unspec/` via `harvester eject`. |
| **`imitm`** | `RuleClassifier::STATE_IMITM` | **Initial Matched Item / Spec Candidate** | Filename matches `rules.primary_spec_regex` (and does not match antipattern) | Targeted by `harvester pull` for staging into `repo/stage/`. |
| **`nnitm`** | `RuleClassifier::STATE_NNITM` | **Non-Item / Neutral Passive File** | Matches neither primary nor antipattern patterns | Preserved untouched and unmutated in the source folder. |
| **`stitm`** | `RuleClassifier::STATE_STITM` | **Staged Item in Transit** | An `imitm` copied to `repo/stage/` with an ID in `stage-ledger.txt` | Promoted to `repo/specs/` via `harvester commit`, or cleared via `purge`. |

---

## 3. Deep-Dive Specification of Item Types

### 3.1 `exitm` (Exit Item / Ejection Antipattern)
- **Conceptual Definition**: A file that represents an antipattern, obsolete draft, scratch file, or decommissioned artifact.
- **Priority Gating**: Evaluated **first**. Even if a file's extension matches `primary_spec_regex`, matching `antipattern_regex` immediately forces the file into `exitm`.
- **Engine Handling**:
  - `PullAction`: Explicitly skipped during scan; never staged.
  - `EjectAction`: Identified for quarantine. Under `--RunBuild`, atomically moved to `unspec/` with collision timestamping (`_collision_YYYYMMDD_HHMMSS`) and parent directory pruning.

### 3.2 `imitm` (Initial Matched Item)
- **Conceptual Definition**: A candidate specification or target source file that satisfies harvesting criteria.
- **Pattern Inheritance**: Inherits the active regex from `rules.primary_spec_regex` (e.g. `/[_-]specs?\.[^.]+$/i` for specs, `/\.zip$/i` for archives, `/\.(?:html?|css|js|php)$/i` for code).
- **Engine Handling**:
  - `PullAction`: Discovered during candidate scan. Checked against `spec_manifest.txt` (manifested items are skipped). Fresh candidates are copied into `repo/stage/` and transition into `stitm`.
  - Assigned an 8-character hex hash ID (e.g. `731b039e`).

### 3.3 `nnitm` (Non-Matching Neutral Item)
- **Conceptual Definition**: A passive file residing within a scanned directory that is neither a harvesting target nor an antipattern.
- **Examples**: General source code when harvesting specs, build scripts, configuration files, images.
- **Engine Handling**:
  - Completely inert. Read-only inspection during scanning; never moved, modified, copied, or deleted.

### 3.4 `stitm` (Staged Item)
- **Conceptual Definition**: An active, intermediate state representing an `imitm` that has been staged into the staging transit buffer (`repo/stage/`).
- **Ledger Invariant**: An item is only an active `stitm` if it is present in `repo/stage/` AND registered as a row in `stage-ledger.txt`:
  ```
  <HASH_ID>,<ORIGIN_LOCATION>,<STAGE_STORAGE_LOCATION>
  ```
- **Engine Handling**:
  - `CommitAction`: Promoted to `repo/specs/` (using cross-drive `FileUtil::safeMove`), appended to `spec-manifest.txt`, and purged from `stage-ledger.txt`.
  - `PurgeAction`: Removed from `repo/stage/` and removed from `stage-ledger.txt`.

---

## 4. State Transition & Lifecycle Diagram

```
[ Source Workspace File ]
           │
           ├──► (Matches antipattern) ─────► exitm ──(harvester eject)──► [ unspec/ ]
           │
           ├──► (Matches primary pattern) ─► imitm ──(harvester pull)───► [ repo/stage/ ] (stitm)
           │                                                                    │
           │                                                               (harvester commit)
           │                                                                    │
           │                                                                    ▼
           │                                                             [ repo/specs/ ] (Manifested)
           │
           └──► (No match) ────────────────► nnitm (Remains unchanged in source)
```

---

## 5. Extensibility via Configuration Patterns

Because item states are purely driven by pattern inheritance, modifying `harvester.config.json` changes item classification without touching engine source code:

1. **Targeting Archive Backups**:
   - Applying `dev/templates/pattern_archive_zip.json` assigns all `.zip` files as `imitm`.
2. **Targeting Web Code**:
   - Applying `dev/templates/pattern_web_code.json` assigns `.html`, `.css`, `.js`, and `.php` as `imitm`.
3. **Filtering Build Artifacts**:
   - Applying `dev/templates/antipattern_build_artifacts.json` assigns `.min.js` and `/vendor/` items as `exitm`.
