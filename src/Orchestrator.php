<?php

namespace VatoHarvester;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Actions\PullAction;
use VatoHarvester\Actions\EjectAction;
use VatoHarvester\Actions\CommitAction;
use VatoHarvester\Actions\PurgeAction;
use VatoHarvester\Actions\DigestAction;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class Orchestrator
{
    private ConfigLoader $config;
    private $lockHandle = null;

    public function __construct(?string $configPath = null)
    {
        $this->config = new ConfigLoader($configPath);
    }

    public static function run(array $argv): void
    {
        $subcommand = null;
        $configPath = null;
        $isPurge    = false;
        $isConfirm  = false;
        $isAll      = false;
        $targetId   = null;

        // Parse CLI arguments
        foreach ($argv as $index => $arg) {
            if ($index === 0) continue;

            if (str_starts_with($arg, '--config=')) {
                $configPath = substr($arg, 9);
            } elseif ($arg === '--purge' || $arg === '-purge') {
                $isPurge = true;
            } elseif ($arg === '--confirm') {
                $isConfirm = true;
            } elseif ($arg === '--all') {
                $isAll = true;
            } elseif (str_starts_with($arg, '--id=')) {
                $targetId = substr($arg, 5);
            } elseif (!str_starts_with($arg, '-') && $subcommand === null) {
                $subcommand = strtolower(trim($arg));
            } elseif (!str_starts_with($arg, '-') && $subcommand === 'commit' && $targetId === null && !$isAll) {
                $targetId = trim($arg);
            }
        }

        $orchestrator = new self($configPath);
        $orchestrator->dispatch($subcommand, $isPurge, $isConfirm, $isAll, $targetId);
    }

    public function dispatch(?string $subcommand, bool $isPurge, bool $isConfirm, bool $isAll, ?string $targetId): void
    {
        if (empty($subcommand) || $subcommand === 'help') {
            $this->printHelp();
            return;
        }

        // Standard strict subcommands only (NO shorthands)
        switch ($subcommand) {
            case 'pull':
                $this->acquireLock();
                $action = new PullAction($this->config);
                $action->execute($isPurge);
                $this->releaseLock();
                break;

            case 'eject':
                if ($isConfirm) {
                    $this->acquireLock();
                }
                $action = new EjectAction($this->config);
                $action->execute($isConfirm);
                if ($isConfirm) {
                    $this->releaseLock();
                }
                break;

            case 'commit':
                $this->acquireLock();
                $action = new CommitAction($this->config);
                $action->execute($targetId, $isAll);
                $this->releaseLock();
                break;

            case 'purge':
                $this->acquireLock();
                $action = new PurgeAction($this->config);
                $action->execute();
                $this->releaseLock();
                break;

            case 'digest':
                $this->acquireLock();
                $action = new DigestAction($this->config);
                $action->execute();
                $this->releaseLock();
                break;

            case 'status':
                $this->printSimpleStatus();
                break;

            default:
                echo "[!] Unknown subcommand: '{$subcommand}'. Run 'harvester' for available subcommands.\n";
                exit(1);
        }
    }

    private function printSimpleStatus(): void
    {
        $stageDir     = $this->config->getPath('stage_dir');
        $specsDir     = $this->config->getPath('repo_items_dir');
        $ejectDir     = $this->config->getPath('eject_dir');
        $manifestFile = $this->config->getPath('spec_manifest');
        $ledgerFile   = $this->config->getPath('stage_ledger');

        $stagedCount   = $this->countDirectoryFiles($stageDir);
        $specsCount    = $this->countDirectoryFiles($specsDir);
        $ejectedCount  = $this->countDirectoryFiles($ejectDir);

        $ledgerCount = 0;
        if (file_exists($ledgerFile)) {
            $ledgerCount = count(file($ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        $manifestCount = 0;
        if (file_exists($manifestFile)) {
            $manifestCount = count(file($manifestFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        echo "========================================================\n";
        echo " Harvester Status Summary\n";
        echo "========================================================\n";
        echo " Staged Items:     {$stagedCount} (Ledger: {$ledgerCount})\n";
        echo " Committed Specs:  {$specsCount} (Manifest: {$manifestCount})\n";
        echo " Ejected Items:    {$ejectedCount}\n";
        echo "========================================================\n";
    }

    private function countDirectoryFiles(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $rIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($rIterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    private function acquireLock(): void
    {
        $lockFile = $this->config->getPath('lock_file');
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->lockHandle = fopen($lockFile, 'c+');
        if (!$this->lockHandle || !flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            echo "[NOTICE] Another harvester process is currently active on this repo.\n";
            echo "         Lockfile: {$lockFile}\n";
            exit(1);
        }
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private function printHelp(): void
    {
        echo "========================================================\n";
        echo " vato-harvester CLI Package\n";
        echo "========================================================\n";
        echo " Usage: php harvester.php <subcommand> [flags]\n\n";
        echo " Available Subcommands:\n";
        echo "   pull      Scan, classify, and stage target items (--purge supported)\n";
        echo "   eject     Dry-run summary of antipatterns (--confirm for actual moves)\n";
        echo "   commit    Promote staged items into repo manifest (--id=<ID> or --all)\n";
        echo "   purge     Clear staging area for non-spec items\n";
        echo "   digest    Generate spec markdown digest report & zip archive\n";
        echo "   status    Display simple line-by-line status summary\n";
        echo "========================================================\n";
    }
}
