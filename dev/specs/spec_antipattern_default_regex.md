# Technical Specification: Default Antipattern Detection Regex

```
# Copyright (c) 2026:
# vatofichor - Sebastian Mass     [>_<]
# & Assisted By Gemini Antigravity /|\
```

---

## 1. Specification Overview

- **Standard Pattern**: `/(?:^|[-_.,0-9])(?:unspec|unitem|exitm)(?:$|[-_.,0-9])/i`
- **Configuration Key**: `rules.antipattern_regex`
- **Engine Classification**: Maps directly to taxonomy state `RuleClassifier::STATE_EXITM` ("Exit / Eject Item").
- **Core Objective**: Automatically detect, isolate, and quarantine deprecated, decommissioned, or non-spec workspace files without accidentally targeting legitimate code or spec documents.

---

## 2. Regular Expression Breakdown

```
/ (?:^|[-_.,0-9]) (?:unspec|unitem|exitm) (?:$|[-_.,0-9]) /i
  |-------------| |-------------------| |-------------|  |
         |                  |                  |         |
   Left Boundary      Target Keywords    Right Boundary  Case Insensitive
```

### 2.1 Pattern Components

| Component | Expression | Functional Mechanics |
| :--- | :--- | :--- |
| **Case Insensitivity** | `/.../i` | Matches uppercase, lowercase, and mixed-case variants (e.g. `unspec`, `UNSPEC`, `UnSpec`). |
| **Left Boundary** | `(?:^\|[-_.,0-9])` | Non-capturing group requiring the keyword to be preceded by the start of the string (`^`), a dash (`-`), underscore (`_`), period (`.`), comma (`,`), or numeric digit (`0-9`). |
| **Target Keywords** | `(?:unspec\|unitem\|exitm)` | Matches the core decommission and ejection keywords: `unspec`, `unitem`, or `exitm`. |
| **Right Boundary** | `(?:$\|[-_.,0-9])` | Non-capturing group requiring the keyword to be followed by the end of the string (`$`), a dash (`-`), underscore (`_`), period (`.`), comma (`,`), or numeric digit (`0-9`). |

### 2.2 Boundary Defense Rationale
The boundary groups `(?:^|[-_.,0-9])` and `(?:$|[-_.,0-9])` are strictly engineered to prevent **false positives** on legitimate words containing target substring sequences.
- Prevents matching words like `unspecified` or `sunspec` where the keyword is part of a standard vocabulary word.
- Permits compound naming schemes common in file systems: `01_unspec.txt`, `feature-unspec.md`, `item.exitm.yaml`.

---

## 3. Targeted Keywords & Morphological Taxonomy Family

The antipattern keywords in the regex (`unspec|unitem|exitm`) are not arbitrary strings; they are the exact morphological inverse of the positive taxonomy captured across the engine.

### 3.1 Taxonomy Pairing Matrix

| Antipattern Token | Semantic Inverse Of | Positive Domain Token | Architectural Meaning |
| :--- | :--- | :--- | :--- |
| **`unspec`** | Inverse of Specification | `spec` / `specs` | A file explicitly decommissioned or marked as non-spec (e.g. `feature-unspec.md`, `unspec_sample.json`). |
| **`unitem`** | Inverse of General Item | `item` / `items` | A workspace file stripped of active item promotion status; legacy origin format. |
| **`exitm`** | Inverse of Import Item (`imitm`) | `imitm` (Initial Matched Item) | "Exit Item": Explicit ejection taxonomy flag for items that must exit the active workspace. |
| **`nonitem`** *(Extended)* | Inverse of Item | `item` | Alternate colloquial form sometimes seen in custom user configurations. |
| **`nonspec`** *(Extended)* | Inverse of Spec | `spec` | Alternate colloquial form used when authors prefix files as `nonspec`. |

### 3.2 Token Morphology & Construction Rules
In the Vatofichor naming system, antipattern identifiers follow predictable naming patterns:
1. **The `un-` Prefix**: Denotes a file explicitly stripped of status (`spec` &rarr; `unspec`, `item` &rarr; `unitem`).
2. **The `ex-` Prefix (Contracted `exitm`)**: Formed from **Exit** + **Item** (`exitm`), mirroring **Import** / **Initial** + **Item** (`imitm`), **Stage** + **Item** (`stitm`), and **Non** + **Item** (`nnitm`).
3. **The Suffix/Infix Position**: Placed before extension (e.g. `sample-unspec.txt`, `record.exitm.yaml`) or as directory name (e.g. `unspec/`).


---

## 4. Evaluation Matrix: Matching vs. Non-Matching

### 4.1 Positive Matches (`STATE_EXITM` &rarr; Targeted for Ejection)

| Filename / Path | Match Explanation |
| :--- | :--- |
| `unspec.txt` | Preceded by `^`, followed by `.`. |
| `feature-unspec.md` | Preceded by `-`, followed by `.`. |
| `01_unspec_config.json` | Preceded by `_`, followed by `_`. |
| `legacy-unitem-v2.txt` | Preceded by `-`, followed by `-`. |
| `billing.exitm.yaml` | Preceded by `.`, followed by `.`. |
| `REPORT_UNSPEC.PDF` | Case-insensitive; preceded by `_`, followed by `.`. |
| `exitm` | Preceded by `^`, followed by `$`. |

### 4.2 Negative Matches (Preserved & Protected from Ejection)

| Filename / Path | Non-Match Defense Rationale |
| :--- | :--- |
| `unspecified_behavior.md` | Followed by letter `i`, not in `[-_.,0-9]` boundary set. |
| `sunspec_solar_driver.php` | Preceded by letter `s`, not in boundary set. |
| `punitem.txt` | Preceded by letter `p`, avoiding false positive on custom names. |
| `exitmodel.php` | Followed by letter `o`, preventing ejection of valid model files. |
| `spec-main.txt` | Does not contain any of the three target keywords. |

---

## 5. Lifecycle & Engine Integration

1. **Workspace Scanning (`WorkspaceScanner`)**:
   - Files matching `rules.antipattern_regex` are flagged as `RuleClassifier::STATE_EXITM`.
2. **Pull Exclusion (`PullAction`)**:
   - `harvester pull` ignores `STATE_EXITM` candidates, guaranteeing that decommissioned files never enter `repo/stage/`.
3. **Ejection Quarantine (`EjectAction`)**:
   - `harvester eject` scans specifically for `STATE_EXITM` candidates across source subdirectories.
   - **Dry-Run Preview**: Summarizes and lists candidate files without mutation.
   - **Live Execution (`--RunBuild`)**: Atomically relocates candidate files to the destination quarantine directory (`unspec/`), automatically appending collision timestamps (`_collision_YYYYMMDD_HHMMSS`) if duplicate filenames exist, and prunes remaining empty parent folders.
