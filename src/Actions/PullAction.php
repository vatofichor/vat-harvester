<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;
use VatoHarvester\Classifier\RuleClassifier;
use VatoHarvester\Util\FileUtil;
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
     * Execute scan, classification, and staging.
     * Default behavior is DRY-RUN preview unless $isRunBuild is true.
     *
     * @param bool $isPurge If true, clear staging & specs directories and reset stage ledger first.
     * @param bool $isRunBuild If true, execute live filesystem changes; if false, execute preview only.
     * @return array Summary metrics
     */
    public function execute(bool $isPurge = false, bool $isRunBuild = false): array
    {
        $diagMsg = null;
        $destRoot = $this->config->resolveEffectiveDestinationRoot(!$isRunBuild, $diagMsg);

        if (!empty($diagMsg)) {
            echo "{$diagMsg}\n";
        }

        if ($isRunBuild && $destRoot === null) {
            throw new \RuntimeException($diagMsg ?? "[CRITICAL ERROR] Destination is inaccessible. Halting live build.");
        }

        $stageDir     = $this->config->getPath('stage_dir');
        $specsDir     = $this->config->getPath('repo_items_dir');
        $manifestFile = $this->config->getPath('spec_manifest');
        $ledgerFile   = $this->config->getPath('stage_ledger');

        if (!$isRunBuild) {
            echo "========================================================\n";
            echo " Pull / Staging Protocol (DRY-RUN PREVIEW)\n";
            echo " Pass '--RunBuild' to execute actual file staging.\n";
            echo "========================================================\n\n";

            return $this->executeDryRun($manifestFile, $isPurge);
        }

        return $this->executeLiveBuild($stageDir, $specsDir, $manifestFile, $ledgerFile, $isPurge);
    }

    /**
     * Dry-run preview execution: scans candidates and displays would-stage items without file mutations.
     */
    private function executeDryRun(string $manifestFile, bool $isPurge): array
    {
        if ($isPurge) {
            echo "[PREVIEW] Purge mode requested: would clear existing staged items and reset ledger.\n";
        }

        $manifestedMap = $this->loadManifestMap($manifestFile);
        $candidates    = $this->scanner->scanCandidates();

        $specScannedCount = 0;
        $skippedCount     = 0;
        $eligible         = [];

        foreach ($candidates as $candidate) {
            $originLoc = $candidate['originLoc'];
            $state     = $this->classifier->classify($originLoc);

            if ($state === RuleClassifier::STATE_IMITM) {
                $specScannedCount++;
                $originKey = strtolower($originLoc);

                if (isset($manifestedMap[$originKey])) {
                    $skippedCount++;
                    continue;
                }

                $eligible[] = $candidate;
            }
        }

        $toStage = $this->filterLatestCandidates($eligible);
        $dedupCount = count($eligible) - count($toStage);

        foreach ($toStage as $candidate) {
            echo "[PREVIEW] WOULD STAGE SPEC: {$candidate['relPath']}\n";
        }

        echo "\n--------------------------------------------------------\n";
        echo " Scanned Specs:       {$specScannedCount}\n";
        echo " Already In Repo:     {$skippedCount}\n";
        if ($this->config->getSelectLatestOnly()) {
            echo " Superseded By Newer: {$dedupCount}\n";
        }
        echo " Candidates to Stage: " . count($toStage) . "\n";
        echo " (Run with '--RunBuild' to write staged items)\n";
        echo "--------------------------------------------------------\n";

        return [
            'mode'     => 'dry-run',
            'scanned'  => $specScannedCount,
            'skipped'  => $skippedCount,
            'staged'   => 0,
            'deduped'  => $dedupCount,
            'preview'  => count($toStage),
        ];
    }

    /**
     * Live build execution: stages files, generates hash IDs, and logs to stage ledger.
     */
    private function executeLiveBuild(string $stageDir, string $specsDir, string $manifestFile, string $ledgerFile, bool $isPurge): array
    {
        FileUtil::ensureDirectory($stageDir);
        FileUtil::ensureDirectory($specsDir);
        $this->ensureFile($manifestFile);
        $this->ensureFile($ledgerFile);

        $purgeCount = 0;
        if ($isPurge) {
            echo "[!] PURGE MODE: Cleaning staging and repository items...\n";
            $purgeCount += $this->purgeDirectoryMatching($specsDir);
            $purgeCount += $this->purgeDirectoryMatching($stageDir);
            FileUtil::ensureDirectory(dirname($ledgerFile));
            file_put_contents($ledgerFile, '');
            echo "[+] Purged {$purgeCount} item(s) and reset stage ledger.\n\n";
        }

        $manifestedMap = $this->loadManifestMap($manifestFile);
        echo "[i] Loaded " . count($manifestedMap) . " existing manifest record(s).\n";

        $candidates = $this->scanner->scanCandidates();

        $specScannedCount = 0;
        $skippedCount     = 0;
        $stagedCount      = 0;
        $eligible         = [];

        foreach ($candidates as $candidate) {
            $originLoc = $candidate['originLoc'];
            $state     = $this->classifier->classify($originLoc);

            if ($state === RuleClassifier::STATE_IMITM) {
                $specScannedCount++;
                $originKey = strtolower($originLoc);

                if (isset($manifestedMap[$originKey])) {
                    $skippedCount++;
                    continue;
                }

                $eligible[] = $candidate;
            }
        }

        $toStage = $this->filterLatestCandidates($eligible);
        $dedupCount = count($eligible) - count($toStage);
        if ($this->config->getSelectLatestOnly() && $dedupCount > 0) {
            echo "[i] Deduplication active: filtered out {$dedupCount} older candidate(s).\n";
        }

        FileUtil::ensureDirectory(dirname($ledgerFile));
        $ledgerHandle = fopen($ledgerFile, 'a');

        foreach ($toStage as $candidate) {
            $originLoc = $candidate['originLoc'];
            $relPath   = $candidate['relPath'];
            $stageStor = $stageDir . DIRECTORY_SEPARATOR . $relPath;
            $destDir   = dirname($stageStor);
            FileUtil::ensureDirectory($destDir);

            if (copy($originLoc, $stageStor)) {
                $hashId = $this->generateHashId();
                fwrite($ledgerHandle, "{$hashId},{$originLoc},{$stageStor}\n");
                $stagedCount++;
                echo "[+] STAGED SPEC [{$hashId}]: {$relPath}\n";
            }
        }

        if ($ledgerHandle) {
            fclose($ledgerHandle);
        }

        echo "\n[+] Live stage complete: {$stagedCount} item(s) staged into {$stageDir}\n";

        return [
            'mode'     => 'live-build',
            'scanned'  => $specScannedCount,
            'skipped'  => $skippedCount,
            'staged'   => $stagedCount,
            'deduped'  => $dedupCount,
            'purged'   => $purgeCount,
            'ledger'   => $ledgerFile,
        ];
    }

    /**
     * Filter eligible candidates to retain only the newest/highest candidate per group.
     *
     * @param array $candidates Eligible candidate records matching STATE_IMITM and not yet manifested
     * @return array Deduplicated candidate records
     */
    private function filterLatestCandidates(array $candidates): array
    {
        if (!$this->config->getSelectLatestOnly() || empty($candidates)) {
            return $candidates;
        }

        $groupingMode = $this->config->getLatestGrouping();
        $groups = [];

        foreach ($candidates as $candidate) {
            $originLoc = $candidate['originLoc'];
            $filename  = $candidate['filename'];

            if ($groupingMode === 'regex') {
                // Strip date/timestamp/version tokens anywhere in filename
                $stem = preg_replace('/[_\-\.](?:\d{8}(?:[_\-T]\d{6})?|\d{4}-\d{2}-\d{2}|v?\d+(?:\.\d+)*)/i', '', $filename);
                $groupKey = dirname($originLoc) . '::' . strtolower($stem ?: $filename);
            } else {
                // Default: group by project directory
                $groupKey = strtolower(dirname($originLoc));
            }

            $groups[$groupKey][] = $candidate;
        }

        $filtered = [];

        foreach ($groups as $groupKey => $groupCandidates) {
            if (count($groupCandidates) === 1) {
                $filtered[] = $groupCandidates[0];
                continue;
            }

            // Sort descending: highest filemtime, then highest natural sort on filename
            usort($groupCandidates, function ($a, $b) {
                $timeA = @filemtime($a['originLoc']) ?: 0;
                $timeB = @filemtime($b['originLoc']) ?: 0;

                if ($timeA !== $timeB) {
                    return $timeB <=> $timeA; // Newest modification first
                }

                return strnatcasecmp($b['filename'], $a['filename']); // Higher version/name first
            });

            // Winner is the newest candidate
            $filtered[] = $groupCandidates[0];
        }

        return $filtered;
    }

    private function generateHashId(): string
    {
        return bin2hex(random_bytes(4));
    }

    private function ensureFile(string $file): void
    {
        if (!file_exists($file)) {
            $dir = dirname($file);
            FileUtil::ensureDirectory($dir);
            file_put_contents($file, '');
        }
    }

    private function loadManifestMap(string $manifestFile): array
    {
        $manifestedMap = [];
        if (empty($manifestFile) || !file_exists($manifestFile)) {
            return $manifestedMap;
        }

        $lines = file($manifestFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = explode(',', $line);
            if (count($parts) >= 3) {
                $origin   = FileUtil::normalizePath($parts[1]);
                $repoStor = FileUtil::normalizePath($parts[2]);
                if (file_exists($repoStor)) {
                    $manifestedMap[strtolower($origin)] = true;
                }
            } elseif (count($parts) >= 2) {
                $origin   = FileUtil::normalizePath($parts[0]);
                $repoStor = FileUtil::normalizePath($parts[1]);
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
