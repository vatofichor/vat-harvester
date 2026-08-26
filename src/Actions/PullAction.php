<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;
use VatoHarvester\Classifier\RuleClassifier;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class PullAction
{
    private ConfigLoader $config;
    private WorkspaceScanner $scanner;
    private RuleClassifier $classifier;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
        $this->scanner = new WorkspaceScanner($config);
        $this->classifier = new RuleClassifier($config);
    }

    /**
     * Execute scan, classification, staging, and ledger logging.
     *
     * @param bool $isPurge If true, clear staging & specs directories and reset stage ledger first.
     * @return array Summary metrics
     */
    public function execute(bool $isPurge = false): array
    {
        $stageDir     = $this->config->getPath('stage_dir');
        $specsDir     = $this->config->getPath('repo_items_dir');
        $manifestFile = $this->config->getPath('spec_manifest');
        $ledgerFile   = $this->config->getPath('stage_ledger');

        // Ensure directories and files exist
        $this->ensureDirectory($stageDir);
        $this->ensureDirectory($specsDir);
        $this->ensureFile($manifestFile);
        $this->ensureFile($ledgerFile);

        $purgeCount = 0;

        // 1. Purge operation if requested
        if ($isPurge) {
            echo "[!] PURGE MODE: Cleaning staging and repository items...\n";
            $purgeCount += $this->purgeDirectoryMatching($specsDir);
            $purgeCount += $this->purgeDirectoryMatching($stageDir);
            file_put_contents($ledgerFile, '');
            echo "[+] Purged {$purgeCount} item(s) and reset stage ledger.\n\n";
        }

        // 2. Load manifest into lookup map to prevent duplicate staging
        $manifestedMap = $this->loadManifestMap($manifestFile);
        echo "[i] Loaded " . count($manifestedMap) . " existing manifest record(s).\n";

        // 3. Scan candidates across workspace root
        $candidates = $this->scanner->scanCandidates();

        $specScannedCount   = 0;
        $skippedCount       = 0;
        $stagedCount        = 0;

        $ledgerHandle = fopen($ledgerFile, 'a');

        foreach ($candidates as $candidate) {
            $originLoc = $candidate['originLoc'];
            $relPath   = $candidate['relPath'];
            $state     = $this->classifier->classify($originLoc);

            if ($state === RuleClassifier::STATE_IMITM) {
                $specScannedCount++;
                $originKey = strtolower($originLoc);

                if (isset($manifestedMap[$originKey])) {
                    $skippedCount++;
                    continue;
                }

                $stageStor = $stageDir . DIRECTORY_SEPARATOR . $relPath;
                $destDir   = dirname($stageStor);
                $this->ensureDirectory($destDir);

                if (copy($originLoc, $stageStor)) {
                    $hashId = $this->generateHashId();
                    fwrite($ledgerHandle, "{$hashId},{$originLoc},{$stageStor}\n");
                    $stagedCount++;
                    echo "[+] STAGED SPEC [{$hashId}]: {$relPath}\n";
                }
            }
        }

        if ($ledgerHandle) {
            fclose($ledgerHandle);
        }

        return [
            'scanned'  => $specScannedCount,
            'skipped'  => $skippedCount,
            'staged'   => $stagedCount,
            'purged'   => $purgeCount,
            'ledger'   => $ledgerFile,
        ];
    }

    private function generateHashId(): string
    {
        return bin2hex(random_bytes(4));
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function ensureFile(string $file): void
    {
        if (!file_exists($file)) {
            $dir = dirname($file);
            $this->ensureDirectory($dir);
            file_put_contents($file, '');
        }
    }

    private function loadManifestMap(string $manifestFile): array
    {
        $manifestedMap = [];
        if (!file_exists($manifestFile)) {
            return $manifestedMap;
        }

        $lines = file($manifestFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = explode(',', $line);
            if (count($parts) >= 3) {
                $origin   = $this->scanner->normalizePath($parts[1]);
                $repoStor = $this->scanner->normalizePath($parts[2]);
                if (file_exists($repoStor)) {
                    $manifestedMap[strtolower($origin)] = true;
                }
            } elseif (count($parts) >= 2) {
                $origin   = $this->scanner->normalizePath($parts[0]);
                $repoStor = $this->scanner->normalizePath($parts[1]);
                if (file_exists($repoStor)) {
                    $manifestedMap[strtolower($origin)] = true;
                }
            }
        }
        return $manifestedMap;
    }

    private function purgeDirectoryMatching(string $dir): int
    {
        $count = 0;
        if (!is_dir($dir)) return $count;

        $rIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        $primaryRegex = $this->config->getRule('primary_spec_regex');

        foreach ($rIterator as $file) {
            if ($file->isFile()) {
                if (empty($primaryRegex) || preg_match($primaryRegex, $file->getFilename())) {
                    @unlink($file->getPathname());
                    $count++;
                }
            }
        }
        return $count;
    }
}
