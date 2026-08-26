<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;
use VatoHarvester\Classifier\RuleClassifier;

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
     *
     * @param bool $isConfirm If false, executes as a dry-run outputting candidate summary line-items.
     * @return array Summary metrics
     */
    public function execute(bool $isConfirm = false): array
    {
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

        if (!$isConfirm) {
            echo "========================================================\n";
            echo " Ejection Protocol (DRY-RUN SUMMARY)\n";
            echo " Pass '--confirm' to execute actual file moves.\n";
            echo "========================================================\n\n";

            if ($totalFound === 0) {
                echo "[i] No antipattern candidate files found.\n";
            } else {
                echo "[!] Discovered {$totalFound} antipattern candidate file(s):\n";
                foreach ($antipatterns as $item) {
                    echo "  - Candidate: {$item['relPath']}\n";
                }
            }

            return [
                'mode'      => 'dry-run',
                'found'     => $totalFound,
                'ejected'   => 0,
                'collisions'=> 0,
            ];
        }

        // Live execution mode (--confirm)
        echo "========================================================\n";
        echo " Ejection Protocol (LIVE EXECUTION)\n";
        echo "========================================================\n\n";

        if (!is_dir($ejectDir)) {
            mkdir($ejectDir, 0777, true);
        }

        foreach ($antipatterns as $item) {
            $originLoc = $item['originLoc'];
            $relPath   = $item['relPath'];

            if (!file_exists($originLoc)) {
                continue;
            }

            $destPath = $ejectDir . DIRECTORY_SEPARATOR . $relPath;
            $destDir  = dirname($destPath);

            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }

            // Handle destination file collisions with timestamp suffix
            if (file_exists($destPath)) {
                $collisionCount++;
                $info = pathinfo($destPath);
                $timestamp = date('Ymd_His');
                $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                $destPath = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . "_collision_{$timestamp}" . $ext;
            }

            // Execute simplex atomic rename()
            if (@rename($originLoc, $destPath)) {
                $ejectedCount++;
                echo "[+] EJECTED: {$relPath} -> " . basename($destPath) . "\n";
                $this->pruneEmptyDirs(dirname($originLoc));
            } else {
                echo "[ERROR] Failed to eject file: {$originLoc}\n";
            }
        }

        return [
            'mode'      => 'confirmed',
            'found'     => $totalFound,
            'ejected'   => $ejectedCount,
            'collisions'=> $collisionCount,
        ];
    }

    /**
     * Recursively delete empty parent directories up to workspace target root.
     */
    private function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) return;
        $targetRoot = $this->config->getTargetRoot();
        $normDir    = $this->scanner->normalizePath($dir);
        $normRoot   = $this->scanner->normalizePath($targetRoot);

        // Do not prune above target root
        if (strlen($normDir) <= strlen($normRoot)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        if (empty($files)) {
            @rmdir($dir);
            $this->pruneEmptyDirs(dirname($dir));
        }
    }
}
