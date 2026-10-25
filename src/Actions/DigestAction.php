<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;
use VatoHarvester\Util\FileUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class DigestAction
{
    private ConfigLoader $config;
    private WorkspaceScanner $scanner;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
        $this->scanner = new WorkspaceScanner($config);
    }

    /**
     * Execute spec digest generation and native PHP zip archiving.
     * Default behavior is DRY-RUN preview unless $isRunBuild is true.
     *
     * @param bool $isRunBuild If true, generate digest file and zip archive; if false, preview only.
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
            throw new \RuntimeException($diagMsg ?? "[CRITICAL ERROR] Destination is inaccessible. Halting live digest.");
        }

        $specsDir   = $this->config->getPath('repo_items_dir');
        $digestsDir = $this->config->getPath('digests_dir');

        if (empty($specsDir) || !is_dir($specsDir)) {
            echo "[!] No committed specs directory found at {$specsDir}.\n";
            return ['digests' => 0, 'archive' => null];
        }

        // Collect all committed spec files
        $specFiles = [];
        $rIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($specsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($rIterator as $file) {
            if ($file->isFile()) {
                $specFiles[] = $file->getPathname();
            }
        }

        $totalSpecs = count($specFiles);

        $isZipDisabled = $this->config->getDisableDigestZip() ||
                         $this->config->getDigestMode() === 'open' ||
                         $this->config->getDigestMode() === 'none';

        if (!$isRunBuild) {
            echo "========================================================\n";
            echo " Digest Protocol (DRY-RUN PREVIEW)\n";
            echo " Pass '--RunBuild' to generate live digest report" . ($isZipDisabled ? ".\n" : " & zip.\n");
            echo "========================================================\n\n";
            echo "[PREVIEW] Total committed specs to include: {$totalSpecs}\n";
            echo "Target Digest Directory: " . ($digestsDir ?: '(Unconfigured)') . "\n";
            echo "Archive Strategy:        " . ($isZipDisabled ? "Open Digest (ZIP packaging bypassed)" : "Native Zip Archive") . "\n";
            echo "--------------------------------------------------------\n";

            return [
                'mode'        => 'dry-run',
                'strategy'    => $isZipDisabled ? 'open-digest' : 'zip-bundle',
                'specs_count' => $totalSpecs,
            ];
        }

        if ($totalSpecs === 0) {
            echo "[!] No committed spec files found to digest.\n";
            return ['digests' => 0, 'archive' => null];
        }

        FileUtil::ensureDirectory($digestsDir);

        // Build Markdown Digest Content via streaming file handle
        $timestamp  = date('Ymd_His');
        $digestFile = $digestsDir . DIRECTORY_SEPARATOR . "digest_{$timestamp}.md";

        $handle = fopen($digestFile, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("[CRITICAL ERROR] Unable to open digest file for writing: {$digestFile}");
        }

        fwrite($handle, "# Specsheets Digest Report ({$timestamp})\n\n");
        fwrite($handle, "```\n# Copyright (c) 2026:\n# vatofichor - Sebastian Mass     [>_<]\n# & Assisted By Gemini Antigravity /|\\\n```\n\n");
        fwrite($handle, "Total Committed Specs: {$totalSpecs}\n");
        fwrite($handle, "Archive Strategy: " . ($isZipDisabled ? "Open Digest (Zero redundant compression)" : "Zip Archive Bundle") . "\n\n");
        fwrite($handle, "## Digest Item Manifest\n\n");

        foreach ($specFiles as $filePath) {
            $normPath = FileUtil::normalizePath($filePath);
            $normSpecs = FileUtil::normalizePath($specsDir);
            $relPath  = ltrim(substr($normPath, strlen($normSpecs)), '\\/');
            $size     = filesize($filePath);
            $sha256   = hash_file('sha256', $filePath);
            fwrite($handle, "- **`{$relPath}`** ({$size} bytes) — `sha256:{$sha256}`\n");
        }

        fclose($handle);
        echo "[+] Generated spec digest markdown: " . basename($digestFile) . "\n";

        // Bypass ZipArchive packaging if disable_digest_zip is enabled
        if ($isZipDisabled) {
            echo "[i] Open digest strategy active: ZipArchive packaging bypassed (saving CPU, memory, and disk space).\n";
            return [
                'mode'     => 'live-build',
                'strategy' => 'open-digest',
                'digests'  => $totalSpecs,
                'markdown' => $digestFile,
                'archive'  => null,
            ];
        }

        // Create native PHP Zip Archive if ZipArchive extension available
        $zipFile = $digestsDir . DIRECTORY_SEPARATOR . "digest_archive_{$timestamp}.zip";
        $archived = false;

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFile($digestFile, basename($digestFile));
                foreach ($specFiles as $filePath) {
                    $normPath = FileUtil::normalizePath($filePath);
                    $normSpecs = FileUtil::normalizePath($specsDir);
                    $relPath  = ltrim(substr($normPath, strlen($normSpecs)), '\\/');
                    $zip->addFile($filePath, 'specs/' . $relPath);
                }
                $zip->close();
                $archived = true;
                echo "[+] Created native zip archive: " . basename($zipFile) . "\n";
            } else {
                echo "[!] Warning: Unable to initialize ZipArchive at {$zipFile}\n";
            }
        } else {
            echo "[i] ZipArchive extension not detected. Skipping zip bundle.\n";
        }

        return [
            'mode'     => 'live-build',
            'strategy' => 'zip-bundle',
            'digests'  => $totalSpecs,
            'markdown' => $digestFile,
            'archive'  => $archived ? $zipFile : null,
        ];
    }
}
