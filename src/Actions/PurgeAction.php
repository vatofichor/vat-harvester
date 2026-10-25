<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Util\FileUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class PurgeAction
{
    private ConfigLoader $config;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
    }

    /**
     * Execute staging purge.
     * Default behavior is DRY-RUN preview unless $isRunBuild is true.
     *
     * @param bool $isRunBuild If true, execute actual purge; if false, preview only.
     * @return array Summary metrics
     */
    public function execute(bool $isRunBuild = false): array
    {
        $diagMsg = null;
        $destRoot = $this->config->resolveEffectiveDestinationRoot(!$isRunBuild, $diagMsg);

        if (!empty($diagMsg)) {
            echo "{$diagMsg}\n";
        }

        if ($isRunBuild && $destRoot === null) {
            throw new \RuntimeException($diagMsg ?? "[CRITICAL ERROR] Destination is inaccessible. Halting live purge.");
        }

        $stageDir   = $this->config->getPath('stage_dir');
        $ledgerFile = $this->config->getPath('stage_ledger');

        $stageCount = 0;
        if (!empty($stageDir) && is_dir($stageDir)) {
            $rIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($stageDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($rIterator as $file) {
                if ($file->isFile()) {
                    $stageCount++;
                }
            }
        }

        $ledgerCount = 0;
        if (!empty($ledgerFile) && file_exists($ledgerFile)) {
            $ledgerCount = count(file($ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        if (!$isRunBuild) {
            echo "========================================================\n";
            echo " Purge Protocol (DRY-RUN PREVIEW)\n";
            echo " Pass '--RunBuild' to execute actual purge.\n";
            echo "========================================================\n\n";
            echo "[PREVIEW] Uncommitted staged files that would be cleared: {$stageCount}\n";
            echo "[PREVIEW] Ledger entries that would be reset:            {$ledgerCount}\n";
            echo "Target Staging Directory: " . ($stageDir ?: '(Unconfigured)') . "\n";
            echo "--------------------------------------------------------\n";

            return [
                'mode'         => 'dry-run',
                'staged_files' => $stageCount,
                'ledger_lines' => $ledgerCount,
            ];
        }

        // Live build purge
        $purgedCount = 0;
        if (!empty($stageDir) && is_dir($stageDir)) {
            $rIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($stageDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($rIterator as $file) {
                if ($file->isFile()) {
                    if (@unlink($file->getPathname())) {
                        $purgedCount++;
                    }
                } elseif ($file->isDir()) {
                    @rmdir($file->getPathname());
                }
            }
        }

        if (!empty($ledgerFile) && file_exists($ledgerFile)) {
            FileUtil::ensureDirectory(dirname($ledgerFile));
            file_put_contents($ledgerFile, '');
        }


        echo "[+] Live purge complete: Removed {$purgedCount} uncommitted staged file(s) and reset stage ledger.\n";

        return [
            'mode'   => 'live-build',
            'purged' => $purgedCount,
        ];
    }
}
