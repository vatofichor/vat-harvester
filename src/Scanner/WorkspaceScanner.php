<?php

namespace VatoHarvester\Scanner;

use VatoHarvester\Config\ConfigLoader;
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
     * Scan target root for project directories (including category folders starting with '_')
     * and discover candidate files within target subdirectories.
     *
     * @return array Array of candidate file records containing 'originLoc', 'relPath', and 'filename'
     */
    public function scanCandidates(): array
    {
        $targetRoot = $this->config->getTargetRoot();
        $targetSubdirs = $this->config->getTargetSubdirs();
        $candidates = [];

        if (!is_dir($targetRoot)) {
            return $candidates;
        }

        // Expand projects including category directories starting with '_' (e.g., _SPECS/*)
        $rawDirs = glob($targetRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
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

        $normTargetRoot = $this->normalizePath($targetRoot);

        foreach ($projects as $projectPath) {
            // Check boundary & symlink rules
            if (is_link($projectPath)) {
                $real = realpath($projectPath);
                $drive = $this->config->getTargetDrive();
                if ($real === false || (!empty($drive) && strpos($this->normalizePath($real), $drive) !== 0)) {
                    continue; // Skip out-of-boundary symlinks
                }
            }

            foreach ($targetSubdirs as $subdir) {
                $searchPath = $projectPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
                if (!is_dir($searchPath)) {
                    continue;
                }

                $files = $this->scanDirectoryFiles($searchPath);
                foreach ($files as $filePath) {
                    $normOrigin = $this->normalizePath($filePath);
                    $relPath = ltrim(substr($normOrigin, strlen($normTargetRoot)), '\\/');

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
        $real = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        return rtrim($real, DIRECTORY_SEPARATOR);
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
