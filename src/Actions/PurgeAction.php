<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
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
     * Execute staging purge for non-specs.
     *
     * @return array Summary metrics
     */
    public function execute(): array
    {
        $stageDir   = $this->config->getPath('stage_dir');
        $ledgerFile = $this->config->getPath('stage_ledger');
        $purgedCount = 0;

        if (is_dir($stageDir)) {
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

        if (file_exists($ledgerFile)) {
            file_put_contents($ledgerFile, '');
        }

        echo "[+] Purged {$purgedCount} uncommitted staged file(s) and reset stage ledger.\n";

        return [
            'purged' => $purgedCount,
        ];
    }
}
