<?php

namespace VatoHarvester\Scanner;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Util\FileUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class WorkspaceScanner
{
    private ConfigLoader $config;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
    }

    /**
     * Scan source root for project directories (including category folders starting with '_')
     * and discover candidate files within target subdirectories.
     *
     * @return array Array of candidate file records containing 'originLoc', 'relPath', and 'filename'
     */
    public function scanCandidates(): array
    {
        $sourceRoot = $this->config->getSourceRoot();
        $sourceSubdirs = $this->config->getSourceSubdirs();
        $sourceDrive = $this->config->getSourceDrive();
        $candidates = [];

        $reason = null;
        if (!$this->config->isSourceAccessible($reason)) {
            throw new \RuntimeException("[CRITICAL ERROR] {$reason}");
        }

        // Expand projects including category directories starting with '_' (e.g., _SPECS/*)
        $rawDirs = glob($sourceRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        $projects = [];
        foreach ($rawDirs as $dir) {
            $dirName = basename($dir);
            if (str_starts_with($dirName, '_')) {
                $subDirs = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
                foreach ($subDirs as $subDir) {
                    $projects[] = $subDir;
                }
            }
            $projects[] = $dir;
        }

        $normSourceRoot = FileUtil::normalizePath($sourceRoot);
        $seenOriginMap = [];

        foreach ($projects as $projectPath) {
            // Check boundary & symlink rules
            if (is_link($projectPath)) {
                $real = realpath($projectPath);
                if ($real === false) {
                    continue;
                }
                $realDrive = FileUtil::parseDrive($real);
                if (!empty($sourceDrive) && $realDrive !== $sourceDrive) {
                    continue; // Skip out-of-boundary symlinks
                }
            }

            foreach ($sourceSubdirs as $subdir) {
                $searchPath = $projectPath . DIRECTORY_SEPARATOR . FileUtil::normalizePath($subdir);
                if (!is_dir($searchPath)) {
                    continue;
                }

                $files = $this->scanDirectoryFiles($searchPath);
                foreach ($files as $filePath) {
                    $normOrigin = FileUtil::normalizePath($filePath);
                    $originKey = strtolower($normOrigin);
                    if (isset($seenOriginMap[$originKey])) {
                        continue;
                    }
                    $seenOriginMap[$originKey] = true;

                    $relPath = ltrim(substr($normOrigin, strlen($normSourceRoot)), '\\/');

                    $candidates[] = [
                        'originLoc' => $normOrigin,
                        'relPath'   => $relPath,
                        'filename'  => basename($normOrigin),
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * Standardized path normalization helper matching origin.
     */
    public function normalizePath(string $path): string
    {
        return FileUtil::normalizePath(rtrim($path, '/\\'));
    }

    /**
     * Recursively list all files under a specific directory.
     */
    private function scanDirectoryFiles(string $dir): array
    {
        $fileList = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            if ($this->config->hasScanMaxDepth()) {
                $iterator->setMaxDepth($this->config->getScanMaxDepth());
            }

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $realPath = $item->getRealPath();
                    if ($realPath !== false) {
                        $fileList[] = $realPath;
                    }
                }
            }
        } catch (\Exception $e) {
            // Log or ignore unreadable directories cleanly
        }

        return $fileList;
    }
}
