# Systems Architecture Specification: The Tri-Cycle Pipeline
## Staging, Commitment, and Digest Cycles

```
# Copyright (c) 2026:
# vatofichor - Sebastian Mass     [>_<]
# & Assisted By Gemini Antigravity /|\
```

---

## 1. Pipeline Overview & Lifecycle Paradigm

The core engine of `vat-harvester` operates on a deterministic, three-stage progressive lifecycle designed to separate **discovery**, **promotion**, and **archival synthesis**:

```
[ Active Workspace ]
        │
        │  1. STAGING CYCLE (harvester pull)
        ▼  Non-destructive copy & hash attribution
[ repo/stage/ ] ──► Tracked in stage-ledger.txt
        │
        │  2. COMMITMENT CYCLE (harvester commit)
        ▼  Atomic / Cross-drive transfer & reconciliation
[ repo/specs/ ] ──► Inscribed in spec-manifest.txt
        │
        │  3. DIGEST CYCLE (harvester digest)
        ▼  Cryptographic cataloging & native ZIP packaging
[ digests/ ]    ──► Immutable <timestamp>.md + <timestamp>.zip
```

Each cycle operates independently, is idempotent, enforces safe non-destructive **Dry-Run previews** by default, and requires the explicit `--RunBuild` flag to perform filesystem mutations.

---

## 2. Cycle 1: The Staging Cycle (`harvester pull` / `PullAction`)

### 2.1 Purpose & Guarantees
The Staging Cycle discovers target items matching `rules.primary_spec_regex` across configured source project subdirectories and stages them into an isolated transit buffer (`repo/stage/`).
- **Source Non-Destructive Invariant**: The source files in the active workspace are **strictly read-only**. They are never modified, renamed, moved, or deleted during a pull.
- **Manifest De-duplication**: Items previously committed to the repository (present in `spec_manifest.txt`) are automatically detected and skipped.

### 2.2 Execution Mechanics
1. **Manifest Ingestion**:
   - Reads `spec_manifest.txt` into an in-memory hash set keyed by normalized origin path (`$manifestedMap[strtolower($originLoc)]`).
2. **Scanner & Classifier Pipeline**:
   - `WorkspaceScanner` crawls all project directories under `source_root` matching `source_subdirs`.
   - `RuleClassifier` evaluates each candidate:
     - `STATE_EXITM`: Antipattern detected &rarr; Skipped.
     - `STATE_NNITM`: Non-matching &rarr; Skipped.
     - `STATE_IMITM`: Positive match &rarr; Evaluated for staging.
3. **Transit Staging**:
   - If not in manifest map, assigns an 8-character cryptographic hexadecimal Hash ID (e.g., `731b039e`).
   - Copies file to `repo/stage/<relPath>`, mirroring project relative directory structure.
   - Appends an entry to the decoupled `stage-ledger.txt`:
     ```csv
     <HASH_ID>,<ORIGIN_LOCATION>,<STAGE_STORAGE_LOCATION>
     ```
4. **Purge Modifier (`--purge`)**:
   - When run with `--RunBuild --purge`, wipes existing files in `repo/stage/` and clears `stage-ledger.txt` before pulling fresh items.

---

## 3. Cycle 2: The Commitment Cycle (`harvester commit` / `CommitAction`)

### 3.1 Purpose & Guarantees
The Commitment Cycle promotes staged transit items (`stitm`) into the permanent, versioned repository storage (`repo/specs/`), permanently records them in the manifest, and reconciles the staging ledger.
- **Transactional State Invariant**: A spec cannot exist in `repo/specs/` without being inscribed in `spec-manifest.txt`.
- **Zero Orphaned Staging**: Promoted files are removed from `repo/stage/`, and empty parent directories inside staging are pruned recursively.

### 3.2 Targeting Scopes
- **Specific Promotion (`--id=<HASH_ID>`)**: Promotes only the specific item matching the 8-character Hash ID in `stage-ledger.txt`.
- **Batch Promotion (`--all`)**: Promotes all items currently recorded in `stage-ledger.txt`.
- **Dry-Run Default**: Previews promotion candidates, target destination paths, and ledger lines without touching the filesystem.

### 3.3 Promotion & Transfer Mechanics (`--RunBuild`)
1. **Ledger Parsing**:
   - Parses active entries from `stage-ledger.txt`. Matches against the targeted scope (`--id` or `--all`).
2. **Safe Transfer (`FileUtil::safeMove`)**:
   - **Intra-Drive**: Executes atomic OS-level `rename()`.
   - **Cross-Drive (`EXDEV` defense)**: Copies file to destination &rarr; asserts exact byte size integrity (`filesize($source) === filesize($dest)`) &rarr; unlinks source file.
3. **Empty Directory Pruning**:
   - Invokes `FileUtil::pruneEmptyDirs()` on the vacated staging subdirectory path, halting at `repo/stage/` boundary.
4. **Manifest Inscription**:
   - Appends promoted records to `spec-manifest.txt`:
     ```csv
     <HASH_ID>,<ORIGIN_LOCATION>,<REPO_STORAGE_LOCATION>
     ```
5. **Ledger Reconciliation**:
   - Rewrites `stage-ledger.txt` containing only remaining uncommitted items. If all staged items were promoted, `stage-ledger.txt` is reduced to 0 bytes.

---

## 4. Cycle 3: The Digest Cycle (`harvester digest` / `DigestAction`)

### 4.1 Purpose & Guarantees
The Digest Cycle acts as the immutable packaging and archival layer. It synthesizes all committed specifications currently housed in `repo/specs/` into a cryptographically verified distribution bundle.
- **Repository Non-Destructive**: Reads files from `repo/specs/` without deleting or altering them.
- **Immutable Timestamping**: Generates paired audit artifacts named `digest_YYYY-MM-DD_HH-II-SS`.

### 4.2 Packaging & Verification Mechanics (`--RunBuild`)
1. **Inventory Scan**:
   - Recursively traverses `repo/specs/` to index all committed specifications.
2. **Cryptographic Checksumming**:
   - Calculates SHA-256 hashes (`hash_file('sha256', $path)`) for every individual file in the repository.
3. **Markdown Digest Catalog (`.md`)**:
   - Generates high-density markdown spec sheet in `digests/` detailing:
     - Total file count and aggregate byte volume.
     - Generation timestamp and host environment metadata.
     - Structured inventory table: Relative Path, File Size (bytes), and SHA-256 Digest.
4. **Native ZIP Archive Generation (`.zip`)**:
   - Leverages PHP's native `ZipArchive` engine (zero third-party dependencies).
   - Bundles all repository specifications preserving their relative directory paths.
   - Embeds the generated Markdown digest catalog into the root of the ZIP archive.

---

## 5. Comparative State & Data Flow Ledger

| Lifecycle Phase | Command | Active File State | Ledger Inscription | Storage Location | Source File Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Discovery** | `harvester pull` | `imitm` &rarr; `stitm` | Appended to `stage-ledger.txt` | `repo/stage/` | **Untouched** (Copied) |
| **Promotion** | `harvester commit` | `stitm` &rarr; Manifested | Inscribed in `spec-manifest.txt`; removed from `stage-ledger.txt` | `repo/specs/` | **Moved** from staging |
| **Synthesis** | `harvester digest` | Manifested Spec | Cataloged with SHA-256 | `digests/*.md` + `digests/*.zip` | **Untouched** (Packaged) |

---

## 6. Concurrency & Integrity Safeguards

1. **Advisory Locking (`repo/.harvester.lock`)**:
   - Mutating commands (`--RunBuild`) acquire an exclusive lock via `flock($fp, LOCK_EX | LOCK_NB)`. Concurrent CLI runs or GUI executions are blocked immediately with a diagnostic message.
2. **Decoupled Path Isolation**:
   - `stage_ledger` and `spec_manifest` destinations can be overridden per configuration profile (e.g. `testing/custom_logs/`), allowing parallel testing suites to run without polluting production ledgers.
3. **Directory Auto-Creation**:
   - All actions call `FileUtil::ensureDirectory(dirname($target))` prior to opening file handles or writing streams, guaranteeing failure-free initialization on fresh installs.
