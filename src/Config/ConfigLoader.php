<?php

namespace VatoHarvester\Config;

use VatoHarvester\Util\FileUtil;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class ConfigLoader
{
    private string $workspaceRoot;
    private string $sourceDrive = '';
    private string $sourceRoot = '';
    private array $sourceSubdirs = ['dev/specs', '.dev/specs', 'ai_persistent_files/dev/specs'];

    private string $destinationDrive = '';
    private string $destinationRoot = '';
    private bool $useDefaultFallback = false;

    private array $paths = [
        'stage_dir'       => 'repo/stage',
        'repo_items_dir'  => 'repo/specs',
        'eject_dir'       => 'unspec',
        'stage_ledger'    => 'stage-ledger.txt',
        'spec_manifest'   => 'spec-manifest.txt',
        'lock_file'       => 'repo/.harvester.lock',
        'digests_dir'     => 'digests',
    ];

    private array $rules = [
        'primary_spec_regex' => '/[_-]specs?\\.[^.]+$/i',
        'antipattern_regex'   => '/(?:^|[-_.,0-9])(?:unspec|unitem|exitm)(?:$|[-_.,0-9])/i',
    ];

    private ?int $scanMaxDepth = null;
    private bool $selectLatestOnly = false;
    private string $latestGrouping = 'directory';
    private bool $disableDigestZip = false;
    private string $digestMode = 'zip';

    public function __construct(?string $configPath = null, ?string $workspaceRoot = null)
    {
        $this->workspaceRoot = $workspaceRoot ? rtrim($workspaceRoot, '\\/') : getcwd();
        $this->loadConfiguration($configPath);
    }

    /**
     * Configuration loader with strict upfront validation for custom configurations.
     * Order of resolution:
     * 1. Explicit $configPath flag argument (Strict upfront validation)
     * 2. harvester.config.json in workspace root
     * 3. config/default.config.json in workspace root
     */
    private function loadConfiguration(?string $configPath = null): void
    {
        $resolvedFile = null;
        $isCustom = false;

        if (!empty($configPath)) {
            $isCustom = true;
            $customPath = $configPath;
            // Resolve relative custom path against working directory/workspace root if needed
            if (!preg_match('/^(?:[a-zA-Z]:|[\\\\\/])/', $customPath)) {
                $customPath = $this->workspaceRoot . DIRECTORY_SEPARATOR . $customPath;
            }

            if (!file_exists($customPath)) {
                throw new \RuntimeException("[CRITICAL CONFIG ERROR] Custom configuration file not found: {$customPath}");
            }
            $resolvedFile = $customPath;
        } elseif (file_exists($this->workspaceRoot . DIRECTORY_SEPARATOR . 'harvester.config.json')) {
            $resolvedFile = $this->workspaceRoot . DIRECTORY_SEPARATOR . 'harvester.config.json';
        } elseif (file_exists($this->workspaceRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'default.config.json')) {
            $resolvedFile = $this->workspaceRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'default.config.json';
        }

        if ($resolvedFile !== null) {
            $raw = file_get_contents($resolvedFile);
            if ($raw === false) {
                throw new \RuntimeException("[CRITICAL CONFIG ERROR] Unable to read configuration file: {$resolvedFile}");
            }

            $json = json_decode($raw, true);
            if ($isCustom && json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("[CRITICAL CONFIG ERROR] Invalid JSON syntax in custom config '{$resolvedFile}': " . json_last_error_msg());
            }

            if (is_array($json)) {
                $this->applyConfigArray($json, $isCustom, $resolvedFile);
            } elseif ($isCustom) {
                throw new \RuntimeException("[CRITICAL CONFIG ERROR] Custom config '{$resolvedFile}' must contain a valid JSON object.");
            }
        }
    }

    /**
     * Apply parsed configuration array and perform upfront type/schema validation.
     */
    private function applyConfigArray(array $json, bool $isCustom, string $resolvedFile): void
    {
        // Fallback flag
        if (isset($json['use_default_fallback'])) {
            $this->useDefaultFallback = (bool)$json['use_default_fallback'];
        }

        // Source drive
        if (!empty($json['source_drive'])) {
            $this->sourceDrive = strtoupper(rtrim((string)$json['source_drive'], '\\/'));
        } elseif (!empty($json['target_drive'])) {
            $this->sourceDrive = strtoupper(rtrim((string)$json['target_drive'], '\\/'));
        }

        // Source root
        $rawSourceRoot = $json['source_root'] ?? ($json['target_root'] ?? null);
        if (!empty($rawSourceRoot)) {
            $this->sourceRoot = $this->resolvePathString((string)$rawSourceRoot);
            if (empty($this->sourceDrive)) {
                $this->sourceDrive = FileUtil::parseDrive($this->sourceRoot);
            }
        }

        // Source subdirs
        $subdirs = $json['source_subdirs'] ?? ($json['target_subdirs'] ?? null);
        if (!empty($subdirs)) {
            if (!is_array($subdirs)) {
                if ($isCustom) {
                    throw new \RuntimeException("[CRITICAL CONFIG ERROR] 'source_subdirs' in '{$resolvedFile}' must be an array.");
                }
            } else {
                $this->sourceSubdirs = $subdirs;
            }
        }

        // Destination drive
        if (!empty($json['destination_drive'])) {
            $this->destinationDrive = strtoupper(rtrim((string)$json['destination_drive'], '\\/'));
        }

        // Destination root
        if (!empty($json['destination_root'])) {
            $this->destinationRoot = $this->resolvePathString((string)$json['destination_root']);
            if (empty($this->destinationDrive)) {
                $this->destinationDrive = FileUtil::parseDrive($this->destinationRoot);
            }
        }

        // Paths mapping
        if (!empty($json['paths'])) {
            if (!is_array($json['paths'])) {
                if ($isCustom) {
                    throw new \RuntimeException("[CRITICAL CONFIG ERROR] 'paths' in '{$resolvedFile}' must be a JSON object.");
                }
            } else {
                $this->paths = array_merge($this->paths, $json['paths']);
            }
        }

        // Rules mapping
        if (!empty($json['rules'])) {
            if (!is_array($json['rules'])) {
                if ($isCustom) {
                    throw new \RuntimeException("[CRITICAL CONFIG ERROR] 'rules' in '{$resolvedFile}' must be a JSON object.");
                }
            } else {
                $this->rules = array_merge($this->rules, $json['rules']);
            }
        }

        // Traversal Depth Control
        if (array_key_exists('scan_max_depth', $json)) {
            $val = $json['scan_max_depth'];
            if ($val === null) {
                $this->scanMaxDepth = null;
            } elseif (is_int($val) && $val >= 0) {
                $this->scanMaxDepth = $val;
            } elseif (is_numeric($val) && (int)$val >= 0 && (string)(int)$val === (string)$val) {
                $this->scanMaxDepth = (int)$val;
            } else {
                if ($isCustom) {
                    throw new \RuntimeException("[CRITICAL CONFIG ERROR] 'scan_max_depth' in '{$resolvedFile}' must be an integer >= 0 or null.");
                }
            }
        }

        // Latest-Only Deduplication Filter
        if (isset($json['select_latest_only'])) {
            $this->selectLatestOnly = (bool)$json['select_latest_only'];
        }
        if (!empty($json['latest_grouping'])) {
            $grouping = strtolower(trim((string)$json['latest_grouping']));
            if (!in_array($grouping, ['directory', 'regex'], true)) {
                if ($isCustom) {
                    throw new \RuntimeException("[CRITICAL CONFIG ERROR] 'latest_grouping' in '{$resolvedFile}' must be 'directory' or 'regex'.");
                }
            } else {
                $this->latestGrouping = $grouping;
            }
        }

        // Digest Archive & Streaming Mode
        if (isset($json['disable_digest_zip'])) {
            $this->disableDigestZip = (bool)$json['disable_digest_zip'];
            if ($this->disableDigestZip && $this->digestMode === 'zip') {
                $this->digestMode = 'open';
            }
        }
        if (!empty($json['digest_mode'])) {
            $mode = strtolower(trim((string)$json['digest_mode']));
            if (!in_array($mode, ['zip', 'open', 'none'], true)) {
                if ($isCustom) {
                    throw new \RuntimeException("[CRITICAL CONFIG ERROR] 'digest_mode' in '{$resolvedFile}' must be 'zip', 'open', or 'none'.");
                }
            } else {
                $this->digestMode = $mode;
                if ($mode === 'open' || $mode === 'none') {
                    $this->disableDigestZip = true;
                }
            }
        }
    }

    /**
     * Resolve path string, converting relative paths to absolute against workspace root.
     */
    private function resolvePathString(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }
        $normalized = FileUtil::normalizePath($trimmed);
        if (!preg_match('/^(?:[a-zA-Z]:|[\\\\\/])/', $normalized)) {
            $normalized = $this->workspaceRoot . DIRECTORY_SEPARATOR . $normalized;
        }
        return FileUtil::normalizePath($normalized);
    }

    public function getWorkspaceRoot(): string
    {
        return $this->workspaceRoot;
    }

    public function getSourceDrive(): string
    {
        if (!empty($this->sourceDrive)) {
            return $this->sourceDrive;
        }
        if (!empty($this->sourceRoot)) {
            return FileUtil::parseDrive($this->sourceRoot);
        }
        return '';
    }

    public function setSourceDrive(string $drive): void
    {
        $this->sourceDrive = strtoupper(rtrim($drive, '\\/'));
    }

    public function getSourceRoot(): string
    {
        return $this->sourceRoot;
    }

    public function setSourceRoot(string $root): void
    {
        $this->sourceRoot = !empty($root) ? $this->resolvePathString($root) : '';
    }

    public function getSourceSubdirs(): array
    {
        return $this->sourceSubdirs;
    }

    public function setSourceSubdirs(array $subdirs): void
    {
        $this->sourceSubdirs = $subdirs;
    }

    public function getDestinationDrive(): string
    {
        if (!empty($this->destinationDrive)) {
            return $this->destinationDrive;
        }
        if (!empty($this->destinationRoot)) {
            return FileUtil::parseDrive($this->destinationRoot);
        }
        if ($this->useDefaultFallback) {
            return FileUtil::parseDrive($this->workspaceRoot);
        }
        return '';
    }

    public function setDestinationDrive(string $drive): void
    {
        $this->destinationDrive = strtoupper(rtrim($drive, '\\/'));
    }

    public function getDestinationRoot(): string
    {
        if (!empty($this->destinationRoot)) {
            return $this->destinationRoot;
        }
        if ($this->useDefaultFallback) {
            return $this->workspaceRoot;
        }
        return '';
    }

    public function setDestinationRoot(string $root): void
    {
        $this->destinationRoot = !empty($root) ? $this->resolvePathString($root) : '';
    }

    public function isDestinationConfigured(): bool
    {
        return !empty($this->destinationRoot) || $this->useDefaultFallback;
    }

    public function getUseDefaultFallback(): bool
    {
        return $this->useDefaultFallback;
    }

    public function setUseDefaultFallback(bool $fallback): void
    {
        $this->useDefaultFallback = $fallback;
    }

    /**
     * Check if destination volume and root are currently mounted and accessible.
     */
    public function isDestinationAccessible(?string &$reason = null): bool
    {
        $destRoot = $this->destinationRoot;

        // If explicit destination root is set
        if (!empty($destRoot)) {
            $drive = FileUtil::parseDrive($destRoot);
            if (!empty($drive) && !is_dir($drive . DIRECTORY_SEPARATOR)) {
                $reason = "Destination drive '{$drive}' is unmounted or offline.";
                return false;
            }
            if (!FileUtil::isAccessible($destRoot)) {
                $reason = "Destination path '{$destRoot}' is inaccessible.";
                return false;
            }
            return true;
        }

        // If unset, verify if fallback is enabled
        if ($this->useDefaultFallback) {
            return is_dir($this->workspaceRoot);
        }

        $reason = "Destination root is not configured and fallback to install root is disabled (--UseDefaultFallback is false).";
        return false;
    }

    /**
     * Check if source volume and directory are mounted and accessible.
     */
    public function isSourceAccessible(?string &$reason = null): bool
    {
        if (empty($this->sourceRoot)) {
            $reason = "Source folder is not configured. A valid source root must be provided in configuration.";
            return false;
        }

        $drive = $this->getSourceDrive();
        if (!empty($drive) && !is_dir($drive . DIRECTORY_SEPARATOR)) {
            $reason = "Source drive '{$drive}' is unmounted or offline.";
            return false;
        }

        if (!is_dir($this->sourceRoot)) {
            $reason = "Source directory does not exist or is inaccessible: '{$this->sourceRoot}'";
            return false;
        }

        return true;
    }

    /**
     * Resolve the effective destination root based on accessibility, fallback flag, and dry-run state.
     * Returns resolved directory path or null if resolution fails.
     */
    public function resolveEffectiveDestinationRoot(bool $isDryRun = false, ?string &$diagnosticMessage = null): ?string
    {
        // 1. If explicit destination root is configured
        if (!empty($this->destinationRoot)) {
            $reason = null;
            if ($this->isDestinationAccessible($reason)) {
                return $this->destinationRoot;
            }

            // Explicit target is inaccessible (e.g. unplugged USB drive)
            if ($isDryRun && $this->useDefaultFallback) {
                $diagnosticMessage = "[NOTICE] Configured destination '{$this->destinationRoot}' is offline ({$reason}). Sourcing install root fallback for preview.";
                return $this->workspaceRoot;
            }

            $diagnosticMessage = "[CRITICAL ERROR] Destination path '{$this->destinationRoot}' is inaccessible ({$reason}).";
            return null;
        }

        // 2. Destination root is unset: check fallback
        if ($this->useDefaultFallback) {
            return $this->workspaceRoot;
        }

        if ($isDryRun) {
            $diagnosticMessage = "[NOTICE] Destination root is unconfigured (UseDefaultFallback=false). Operating in preview-only mode.";
            return null;
        }

        $diagnosticMessage = "[CRITICAL ERROR] Destination root is unconfigured and fallback is disabled (--UseDefaultFallback is false). Halting live build.";
        return null;
    }

    /**
     * Resolve full path for a destination artifact key.
     * Returns resolved path string, or empty string if destination is not configured and fallback is false.
     */
    public function getPath(string $key): string
    {
        $raw = $this->paths[$key] ?? '';
        if ($raw === '') {
            return '';
        }

        // Dedicated resolution for stage_ledger and spec_manifest per-config decoupling
        if ($key === 'stage_ledger' || $key === 'spec_manifest') {
            return $this->resolveLogFilePath($key, $raw);
        }

        $base = $this->getDestinationRoot();
        if (empty($base)) {
            return '';
        }

        $normalized = FileUtil::normalizePath($raw);
        if (preg_match('/^(?:[a-zA-Z]:|[\\\\\/])/', $normalized)) {
            return $normalized;
        }

        return rtrim($base, '\\/') . DIRECTORY_SEPARATOR . $normalized;
    }

    /**
     * Resolve decoupled stage-ledger or spec-manifest path.
     * Supports:
     * 1. Absolute file or directory path
     * 2. Directory target (appends standard filename 'stage-ledger.txt' or 'spec-manifest.txt')
     * 3. Relative path resolved against effective destination root, or workspace root (fallback)
     */
    private function resolveLogFilePath(string $key, string $raw): string
    {
        $defaultFilename = ($key === 'stage_ledger') ? 'stage-ledger.txt' : 'spec-manifest.txt';
        $normalized      = FileUtil::normalizePath(trim($raw));

        // Determine if target is intended as a directory (trailing slash, existing dir, or no extension)
        $hasDirSlash = str_ends_with($raw, '/') || str_ends_with($raw, '\\');
        $isDirTarget = $hasDirSlash || is_dir($normalized) || pathinfo($normalized, PATHINFO_EXTENSION) === '';

        if ($isDirTarget) {
            $normalized = rtrim($normalized, '\\/') . DIRECTORY_SEPARATOR . $defaultFilename;
        }

        // Absolute path returns directly
        if (preg_match('/^(?:[a-zA-Z]:|[\\\\\/])/', $normalized)) {
            return $normalized;
        }

        // Determine base: configured destination root, or workspace root (defaults/fallback maintaining root versions)
        $destRoot = $this->getDestinationRoot();
        $base     = !empty($destRoot) ? $destRoot : $this->workspaceRoot;

        return rtrim($base, '\\/') . DIRECTORY_SEPARATOR . $normalized;
    }


    public function getRelativePath(string $key): string
    {
        return $this->paths[$key] ?? '';
    }

    public function getRule(string $key): string
    {
        return $this->rules[$key] ?? '';
    }

    public function getScanMaxDepth(): ?int
    {
        return $this->scanMaxDepth;
    }

    public function setScanMaxDepth(?int $depth): void
    {
        $this->scanMaxDepth = $depth;
    }

    public function hasScanMaxDepth(): bool
    {
        return $this->scanMaxDepth !== null;
    }

    public function getSelectLatestOnly(): bool
    {
        return $this->selectLatestOnly;
    }

    public function setSelectLatestOnly(bool $val): void
    {
        $this->selectLatestOnly = $val;
    }

    public function getLatestGrouping(): string
    {
        return $this->latestGrouping;
    }

    public function setLatestGrouping(string $grouping): void
    {
        $this->latestGrouping = strtolower(trim($grouping));
    }

    public function getDisableDigestZip(): bool
    {
        return $this->disableDigestZip;
    }

    public function setDisableDigestZip(bool $val): void
    {
        $this->disableDigestZip = $val;
        if ($val && $this->digestMode === 'zip') {
            $this->digestMode = 'open';
        }
    }

    public function getDigestMode(): string
    {
        return $this->digestMode;
    }

    public function setDigestMode(string $mode): void
    {
        $this->digestMode = strtolower(trim($mode));
        if ($this->digestMode === 'open' || $this->digestMode === 'none') {
            $this->disableDigestZip = true;
        }
    }

    // --- Backward Compatibility Aliases ---
    public function getTargetDrive(): string
    {
        return $this->getSourceDrive();
    }

    public function setTargetDrive(string $drive): void
    {
        $this->setSourceDrive($drive);
    }

    public function getTargetRoot(): string
    {
        return $this->getSourceRoot();
    }

    public function setTargetRoot(string $root): void
    {
        $this->setSourceRoot($root);
    }

    public function getTargetSubdirs(): array
    {
        return $this->getSourceSubdirs();
    }

    public function setTargetSubdirs(array $subdirs): void
    {
        $this->setSourceSubdirs($subdirs);
    }
}
