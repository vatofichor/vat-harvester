<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;

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
     *
     * @param string|null $targetId Single 8-char Hash ID or null for bulk commit.
     * @param bool $isAll If true, commit all items in staging ledger.
     * @return array Summary metrics
     */
    public function execute(?string $targetId = null, bool $isAll = false): array
    {
        $specsDir     = $this->config->getPath('repo_items_dir');
        $stageDir     = $this->config->getPath('stage_dir');
        $manifestFile = $this->config->getPath('spec_manifest');
        $ledgerFile   = $this->config->getPath('stage_ledger');

        if (!file_exists($ledgerFile)) {
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
                $originLoc = $this->scanner->normalizePath(trim($parts[1]));
                $stageStor = $this->scanner->normalizePath(trim($parts[2]));

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
                echo "[!] No items selected for commit.\n";
            }
            return ['committed' => 0, 'failed' => 0];
        }

        if (!is_dir($specsDir)) {
            mkdir($specsDir, 0777, true);
        }

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
            $normStageDir = $this->scanner->normalizePath($stageDir);
            if (str_starts_with(strtolower($stageStor), strtolower($normStageDir))) {
                $relPath = ltrim(substr($stageStor, strlen($normStageDir)), '\\/');
            } else {
                $relPath = basename($stageStor);
            }

            $repoStor = $specsDir . DIRECTORY_SEPARATOR . $relPath;
            $destDir  = dirname($repoStor);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }

            // Transactional transfer: copy -> verify size -> unlink -> write manifest
            if (copy($stageStor, $repoStor)) {
                if (file_exists($repoStor) && filesize($repoStor) === filesize($stageStor)) {
                    unlink($stageStor);
                    fwrite($manifestHandle, "{$id},{$originLoc},{$repoStor}\n");
                    $committedCount++;
                    echo "[+] COMMITTED SPEC [{$id}]: {$relPath}\n";
                    $this->pruneEmptyDirs(dirname($stageStor));
                } else {
                    echo "[ERROR] Verification failed for spec [{$id}]: {$repoStor}\n";
                    $failedCount++;
                }
            } else {
                echo "[ERROR] Failed to promote spec [{$id}]: {$stageStor}\n";
                $failedCount++;
            }
        }

        fclose($manifestHandle);

        // Rewrite stage-ledger.txt with remaining uncommitted items
        $newLedgerContent = empty($remainingLines) ? '' : implode("\n", $remainingLines) . "\n";
        file_put_contents($ledgerFile, $newLedgerContent);

        return [
            'committed' => $committedCount,
            'failed'    => $failedCount,
        ];
    }

    private function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) return;
        $stageDir  = $this->config->getPath('stage_dir');
        $normDir   = $this->scanner->normalizePath($dir);
        $normStage = $this->scanner->normalizePath($stageDir);

        if (strlen($normDir) <= strlen($normStage)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        if (empty($files)) {
            @rmdir($dir);
            $this->pruneEmptyDirs(dirname($dir));
        }
    }
}
