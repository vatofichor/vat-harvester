<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;
use VatoHarvester\Util\FileUtil;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class CommitAction
{
    private ConfigLoader $config;
    private WorkspaceScanner $scanner;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
        $this->scanner = new WorkspaceScanner($config);
    }

    /**
     * Execute item promotion from staging to repository.
     * Default behavior is DRY-RUN preview unless $isRunBuild is true.
     *
     * @param string|null $targetId Single 8-char Hash ID or null for bulk commit.
     * @param bool $isAll If true, commit all items in staging ledger.
     * @param bool $isRunBuild If true, execute live promotion; if false, preview only.
     * @return array Summary metrics
     */
    public function execute(?string $targetId = null, bool $isAll = false, bool $isRunBuild = false): array
    {
        $diagMsg = null;
        $destRoot = $this->config->resolveEffectiveDestinationRoot(!$isRunBuild, $diagMsg);

        if (!empty($diagMsg)) {
            echo "{$diagMsg}\n";
        }

        if ($isRunBuild && $destRoot === null) {
            throw new \RuntimeException($diagMsg ?? "[CRITICAL ERROR] Destination is inaccessible. Halting live commit.");
        }

        $specsDir     = $this->config->getPath('repo_items_dir');
        $stageDir     = $this->config->getPath('stage_dir');
        $manifestFile = $this->config->getPath('spec_manifest');
        $ledgerFile   = $this->config->getPath('stage_ledger');

        if (empty($ledgerFile) || !file_exists($ledgerFile)) {
            echo "[!] Staging ledger file not found. Nothing to commit.\n";
            return ['committed' => 0, 'failed' => 0];
        }

        $ledgerLines = file($ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (empty($ledgerLines)) {
            echo "[!] Staging ledger is empty. No staged items available.\n";
            return ['committed' => 0, 'failed' => 0];
        }

        $toCommit = [];
        $remainingLines = [];

        foreach ($ledgerLines as $line) {
            $trimmedLine = trim($line);
            if (empty($trimmedLine)) continue;

            $parts = explode(',', $trimmedLine);
            if (count($parts) >= 3) {
                $id        = trim($parts[0]);
                $originLoc = FileUtil::normalizePath(trim($parts[1]));
                $stageStor = FileUtil::normalizePath(trim($parts[2]));

                if ($isAll || ($targetId !== null && strcasecmp($id, $targetId) === 0)) {
                    $toCommit[] = [
                        'id'         => $id,
                        'origin_loc' => $originLoc,
                        'stage_stor' => $stageStor,
                    ];
                    continue;
                }
            }
            $remainingLines[] = $trimmedLine;
        }

        if (empty($toCommit)) {
            if ($targetId !== null) {
                echo "[!] ERROR: Staged item with ID [{$targetId}] not found in staging ledger.\n";
            } else {
                echo "[!] No items selected for commit. Pass an ID or '--all'.\n";
            }
            return ['committed' => 0, 'failed' => 0];
        }

        if (!$isRunBuild) {
            echo "========================================================\n";
            echo " Commit Protocol (DRY-RUN PREVIEW)\n";
            echo " Pass '--RunBuild' to execute actual item promotion.\n";
            echo "========================================================\n\n";

            foreach ($toCommit as $item) {
                $id        = $item['id'];
                $stageStor = $item['stage_stor'];
                $exists    = file_exists($stageStor) ? "EXISTS" : "MISSING ON DISK";
                echo "[PREVIEW] WOULD COMMIT SPEC [{$id}]: " . basename($stageStor) . " [{$exists}]\n";
            }

            echo "\n--------------------------------------------------------\n";
            echo " Items to Commit: " . count($toCommit) . "\n";
            echo " (Run with '--RunBuild' to promote staged specs into repo)\n";
            echo "--------------------------------------------------------\n";

            return [
                'mode'         => 'dry-run',
                'would_commit' => count($toCommit),
                'committed'    => 0,
                'failed'       => 0,
            ];
        }

        // --- Live Build Promotion ---
        FileUtil::ensureDirectory($specsDir);
        FileUtil::ensureDirectory(dirname($manifestFile));
        FileUtil::ensureDirectory(dirname($ledgerFile));

        $committedCount = 0;
        $failedCount    = 0;
        $manifestHandle = fopen($manifestFile, 'a');

        foreach ($toCommit as $item) {
            $id        = $item['id'];
            $originLoc = $item['origin_loc'];
            $stageStor = $item['stage_stor'];

            if (!file_exists($stageStor)) {
                echo "[!] ERROR [{$id}]: Staged file not found at {$stageStor}\n";
                $failedCount++;
                continue;
            }

            // Calculate relative path inside repo/stage
            $normStageDir = FileUtil::normalizePath($stageDir);
            if (str_starts_with(strtolower($stageStor), strtolower($normStageDir))) {
                $relPath = ltrim(substr($stageStor, strlen($normStageDir)), '\\/');
            } else {
                $relPath = basename($stageStor);
            }

            $repoStor = $specsDir . DIRECTORY_SEPARATOR . $relPath;
            $destDir  = dirname($repoStor);
            FileUtil::ensureDirectory($destDir);

            try {
                // Cross-device safe move with verified size integrity
                FileUtil::safeMove($stageStor, $repoStor);
                fwrite($manifestHandle, "{$id},{$originLoc},{$repoStor}\n");
                $committedCount++;
                echo "[+] COMMITTED SPEC [{$id}]: {$relPath}\n";
                FileUtil::pruneEmptyDirs(dirname($stageStor), $stageDir);
            } catch (\Exception $e) {
                echo "[ERROR] Failed to promote spec [{$id}]: " . $e->getMessage() . "\n";
                $failedCount++;
            }
        }

        if ($manifestHandle) {
            fclose($manifestHandle);
        }

        // Rewrite stage-ledger.txt with remaining uncommitted items
        $newLedgerContent = empty($remainingLines) ? '' : implode("\n", $remainingLines) . "\n";
        FileUtil::ensureDirectory(dirname($ledgerFile));
        file_put_contents($ledgerFile, $newLedgerContent);


        echo "\n[+] Live commit complete: {$committedCount} spec(s) promoted into {$specsDir}\n";

        return [
            'mode'      => 'live-build',
            'committed' => $committedCount,
            'failed'    => $failedCount,
        ];
    }
}
