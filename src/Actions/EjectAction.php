<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;
use VatoHarvester\Classifier\RuleClassifier;
use VatoHarvester\Util\FileUtil;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class EjectAction
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
     * Execute antipattern ejection logic.
     * Default behavior is DRY-RUN preview unless $isRunBuild or $isConfirm is true.
     *
     * @param bool $isConfirm Legacy flag for live execution.
     * @param bool $isRunBuild Standard flag for live execution.
     * @return array Summary metrics
     */
    public function execute(bool $isConfirm = false, bool $isRunBuild = false): array
    {
        $isLive = $isConfirm || $isRunBuild;

        $diagMsg = null;
        $destRoot = $this->config->resolveEffectiveDestinationRoot(!$isLive, $diagMsg);

        if (!empty($diagMsg)) {
            echo "{$diagMsg}\n";
        }

        if ($isLive && $destRoot === null) {
            throw new \RuntimeException($diagMsg ?? "[CRITICAL ERROR] Destination is inaccessible. Halting live eject.");
        }

        $ejectDir = $this->config->getPath('eject_dir');
        $candidates = $this->scanner->scanCandidates();

        $antipatterns = [];
        foreach ($candidates as $candidate) {
            $state = $this->classifier->classify($candidate['originLoc']);
            if ($state === RuleClassifier::STATE_EXITM || $state === RuleClassifier::STATE_NNITM) {
                $antipatterns[] = $candidate;
            }
        }

        $totalFound = count($antipatterns);
        $ejectedCount = 0;
        $collisionCount = 0;

        if (!$isLive) {
            echo "========================================================\n";
            echo " Ejection Protocol (DRY-RUN SUMMARY)\n";
            echo " Pass '--RunBuild' (or '--confirm') to execute actual moves.\n";
            echo "========================================================\n\n";

            if ($totalFound === 0) {
                echo "[i] No antipattern candidate files found.\n";
            } else {
                echo "[!] Discovered {$totalFound} antipattern candidate file(s):\n";
                foreach ($antipatterns as $item) {
                    echo "  - Candidate: {$item['relPath']}\n";
                }
            }

            echo "\n--------------------------------------------------------\n";
            echo " Total Antipattern Candidates: {$totalFound}\n";
            echo " Target Ejection Directory:   " . ($ejectDir ?: '(Unconfigured)') . "\n";
            echo "--------------------------------------------------------\n";

            return [
                'mode'       => 'dry-run',
                'found'      => $totalFound,
                'ejected'    => 0,
                'collisions' => 0,
            ];
        }

        // Live execution mode
        echo "========================================================\n";
        echo " Ejection Protocol (LIVE EXECUTION)\n";
        echo "========================================================\n\n";

        FileUtil::ensureDirectory($ejectDir);

        foreach ($antipatterns as $item) {
            $originLoc = $item['originLoc'];
            $relPath   = $item['relPath'];

            if (!file_exists($originLoc)) {
                continue;
            }

            $destPath = $ejectDir . DIRECTORY_SEPARATOR . $relPath;
            $destDir  = dirname($destPath);
            FileUtil::ensureDirectory($destDir);

            // Handle destination file collisions with timestamp suffix
            if (file_exists($destPath)) {
                $collisionCount++;
                $info = pathinfo($destPath);
                $timestamp = date('Ymd_His');
                $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                $destPath = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . "_collision_{$timestamp}" . $ext;
            }

            try {
                // Cross-device safe move with verified size integrity
                FileUtil::safeMove($originLoc, $destPath);
                $ejectedCount++;
                echo "[+] EJECTED: {$relPath} -> " . basename($destPath) . "\n";
                FileUtil::pruneEmptyDirs(dirname($originLoc), $this->config->getSourceRoot());
            } catch (\Exception $e) {
                echo "[ERROR] Failed to eject file: {$originLoc} (" . $e->getMessage() . ")\n";
            }
        }

        echo "\n[+] Live ejection complete: {$ejectedCount} file(s) moved to {$ejectDir}\n";

        return [
            'mode'       => 'live-build',
            'found'      => $totalFound,
            'ejected'    => $ejectedCount,
            'collisions' => $collisionCount,
        ];
    }
}
