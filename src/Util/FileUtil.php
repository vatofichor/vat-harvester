<?php

namespace VatoHarvester\Util;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class FileUtil
{
    /**
     * Parse Windows or POSIX drive identifier from a path.
     * Returns uppercase drive letter with colon (e.g., 'D:', 'E:', 'G:') or empty string.
     */
    public static function parseDrive(string $path): string
    {
        if (preg_match('/^([a-zA-Z]:)/', $path, $matches)) {
            return strtoupper($matches[1]);
        }
        return '';
    }

    /**
     * Check if two paths reside on the same drive volume.
     */
    public static function isSameDrive(string $pathA, string $pathB): bool
    {
        $driveA = self::parseDrive($pathA);
        $driveB = self::parseDrive($pathB);

        // If neither has an explicit drive letter, assume intra-volume relative paths
        if ($driveA === '' && $driveB === '') {
            return true;
        }

        return $driveA === $driveB;
    }

    /**
     * Check if a drive or directory path is currently mounted and accessible.
     */
    public static function isAccessible(string $path): bool
    {
        $drive = self::parseDrive($path);
        if ($drive !== '') {
            $driveRoot = $drive . DIRECTORY_SEPARATOR;
            if (!is_dir($driveRoot)) {
                return false;
            }
        }
        return file_exists($path) || is_dir(dirname($path));
    }

    /**
     * Cross-volume safe file move.
     * Performs atomic rename() if on the same volume.
     * Falls back to copy() + size verification + unlink() across volume boundaries.
     *
     * @param string $source
     * @param string $destination
     * @return bool
     * @throws \RuntimeException If move or integrity verification fails
     */
    public static function safeMove(string $source, string $destination): bool
    {
        if (!file_exists($source)) {
            throw new \RuntimeException("Source file does not exist: {$source}");
        }

        $destDir = dirname($destination);
        self::ensureDirectory($destDir);

        if (self::isSameDrive($source, $destination)) {
            if (@rename($source, $destination)) {
                return true;
            }
            // If rename fails (e.g. cross-volume junction or permission edge-case), fall through to copy + unlink
        }

        // Cross-device transfer: copy -> verify size -> unlink
        $srcSize = filesize($source);
        if (!@copy($source, $destination)) {
            $err = error_get_last()['message'] ?? 'Unknown error';
            throw new \RuntimeException("Cross-drive copy failed from '{$source}' to '{$destination}': {$err}");
        }

        clearstatcache(true, $destination);
        $dstSize = filesize($destination);

        if ($srcSize !== $dstSize) {
            @unlink($destination);
            throw new \RuntimeException("Integrity check failed: source size ({$srcSize}) != dest size ({$dstSize}) for '{$destination}'. Destination file removed.");
        }

        if (!@unlink($source)) {
            throw new \RuntimeException("Copied to destination but failed to remove original source file: {$source}");
        }

        return true;
    }

    /**
     * Ensure directory exists, creating recursively if missing.
     */
    public static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }
    }

    /**
     * Recursively prune empty parent directories up to a stop directory.
     */
    public static function pruneEmptyDirs(string $dir, string $stopAt): void
    {
        $realStop = realpath($stopAt);
        $realDir  = realpath($dir);

        if ($realStop === false || $realDir === false) {
            return;
        }

        while ($realDir !== false && strlen($realDir) > strlen($realStop) && str_starts_with($realDir, $realStop)) {
            $items = @scandir($realDir);
            if ($items !== false && count($items) <= 2) { // only '.' and '..'
                @rmdir($realDir);
                $realDir = realpath(dirname($realDir));
            } else {
                break;
            }
        }
    }

    /**
     * Standardize path separators to system DIRECTORY_SEPARATOR and collapse relative tokens.
     */
    public static function normalizePath(string $path): string
    {
        // Only resolve realpath on paths that are already absolute
        if (preg_match('/^(?:[a-zA-Z]:|[\\\\\/])/', $path)) {
            $real = @realpath($path);
            if ($real !== false) {
                return $real;
            }
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        // Collapse /./ or \.\
        $normalized = preg_replace('/(?<=[\\\\\/])\.[\\\\\/]/', '', $normalized);
        $normalized = preg_replace('/^\.[\\\\\/]/', '', $normalized);
        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }
}
