<?php

namespace VatoHarvester\Actions;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;
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
     *
     * @return array Summary metrics
     */
    public function execute(): array
    {
        $specsDir   = $this->config->getPath('repo_items_dir');
        $digestsDir = $this->config->getPath('digests_dir');

        if (!is_dir($digestsDir)) {
            mkdir($digestsDir, 0777, true);
        }

        if (!is_dir($specsDir)) {
            echo "[!] No committed specs directory found at {$specsDir}.\n";
            return ['digests' => 0, 'archive' => null];
        }

        // 1. Collect all committed spec files
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
        if ($totalSpecs === 0) {
            echo "[!] No committed spec files found to digest.\n";
            return ['digests' => 0, 'archive' => null];
        }

        // 2. Build Markdown Digest Content
        $timestamp  = date('Ymd_His');
        $digestFile = $digestsDir . DIRECTORY_SEPARATOR . "digest_{$timestamp}.md";

        $content  = "# Specsheets Digest Report ({$timestamp})\n\n";
        $content .= "```\n# Copyright (c) 2026:\n# vatofichor - Sebastian Mass     [>_<]\n# & Assisted By Gemini Antigravity /|\\\n```\n\n";
        $content .= "Total Committed Specs: {$totalSpecs}\n\n";
        $content .= "## Digest Item Manifest\n\n";

        foreach ($specFiles as $filePath) {
            $normPath = $this->scanner->normalizePath($filePath);
            $normSpecs = $this->scanner->normalizePath($specsDir);
            $relPath  = ltrim(substr($normPath, strlen($normSpecs)), '\\/');
            $size     = filesize($filePath);
            $content .= "- **`{$relPath}`** ({$size} bytes)\n";
        }

        file_put_contents($digestFile, $content);
        echo "[+] Generated spec digest markdown: " . basename($digestFile) . "\n";

        // 3. Create native PHP Zip Archive if ZipArchive extension available
        $zipFile = $digestsDir . DIRECTORY_SEPARATOR . "digest_archive_{$timestamp}.zip";
        $archived = false;

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFile($digestFile, basename($digestFile));
                foreach ($specFiles as $filePath) {
                    $normPath = $this->scanner->normalizePath($filePath);
                    $normSpecs = $this->scanner->normalizePath($specsDir);
                    $relPath  = ltrim(substr($normPath, strlen($normSpecs)), '\\/');
                    $zip->addFile($filePath, 'specs/' . $relPath);
                }
                $zip->close();
                $archived = true;
                echo "[+] Created native PHP zip archive: " . basename($zipFile) . "\n";
            }
        }

        return [
            'digests' => $totalSpecs,
            'digest_file' => $digestFile,
            'archive' => $archived ? $zipFile : null,
        ];
    }
}
