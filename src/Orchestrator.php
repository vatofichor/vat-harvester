<?php

namespace VatoHarvester;

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Actions\PullAction;
use VatoHarvester\Actions\EjectAction;
use VatoHarvester\Actions\CommitAction;
use VatoHarvester\Actions\PurgeAction;
use VatoHarvester\Actions\DigestAction;
use VatoHarvester\Util\FileUtil;
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
        $subcommand         = null;
        $configPath         = null;
        $isPurge            = false;
        $isConfirm          = false;
        $isAll              = false;
        $isRunBuild         = false;
        $useDefaultFallback = false;
        $targetId           = null;
        $selectLatest       = null;
        $disableZip         = null;
        $scanMaxDepth       = null;

        $argc = count($argv);
        for ($i = 1; $i < $argc; $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--config=')) {
                $configPath = substr($arg, 9);
            } elseif ($arg === '--config' && isset($argv[$i + 1])) {
                $configPath = $argv[++$i];
            } elseif (strcasecmp($arg, '--RunBuild') === 0) {
                // Check if followed by true/false value
                if (isset($argv[$i + 1]) && in_array(strtolower($argv[$i + 1]), ['true', '1'])) {
                    $i++;
                }
                $isRunBuild = true;
            } elseif (preg_match('/^--RunBuild=(true|1)$/i', $arg)) {
                $isRunBuild = true;
            } elseif (strcasecmp($arg, '--DryRun') === 0 || preg_match('/^--DryRun=(true|1)$/i', $arg)) {
                $isRunBuild = false;
            } elseif (strcasecmp($arg, '--UseDefaultFallback') === 0) {
                if (isset($argv[$i + 1]) && in_array(strtolower($argv[$i + 1]), ['true', '1'])) {
                    $i++;
                }
                $useDefaultFallback = true;
            } elseif (preg_match('/^--UseDefaultFallback=(true|1)$/i', $arg)) {
                $useDefaultFallback = true;
            } elseif ($arg === '--purge' || $arg === '-purge') {
                $isPurge = true;
            } elseif ($arg === '--confirm') {
                $isConfirm = true;
            } elseif ($arg === '--all') {
                $isAll = true;
            } elseif ($arg === '--select-latest' || preg_match('/^--select-latest=(true|1)$/i', $arg)) {
                $selectLatest = true;
            } elseif (preg_match('/^--select-latest=(false|0)$/i', $arg)) {
                $selectLatest = false;
            } elseif ($arg === '--disable-zip' || $arg === '--open-digest' || preg_match('/^--disable-zip=(true|1)$/i', $arg)) {
                $disableZip = true;
            } elseif (str_starts_with($arg, '--latest-grouping=')) {
                $latestGrouping = substr($arg, 18);
            } elseif ($arg === '--latest-grouping' && isset($argv[$i + 1])) {
                $latestGrouping = $argv[++$i];
            } elseif (str_starts_with($arg, '--scan-max-depth=')) {
                $scanMaxDepth = (int)substr($arg, 17);
            } elseif ($arg === '--scan-max-depth' && isset($argv[$i + 1])) {
                $scanMaxDepth = (int)$argv[++$i];
            } elseif (str_starts_with($arg, '--id=')) {
                $targetId = substr($arg, 5);
            } elseif (!str_starts_with($arg, '-') && $subcommand === null) {
                $subcommand = strtolower(trim($arg));
            } elseif (!str_starts_with($arg, '-') && $subcommand === 'commit' && $targetId === null && !$isAll) {
                $targetId = trim($arg);
            }
        }

        try {
            $orchestrator = new self($configPath);
            if ($useDefaultFallback) {
                $orchestrator->config->setUseDefaultFallback(true);
            }
            if ($selectLatest !== null) {
                $orchestrator->config->setSelectLatestOnly($selectLatest);
            }
            if (isset($latestGrouping) && !empty($latestGrouping)) {
                $orchestrator->config->setLatestGrouping($latestGrouping);
            }
            if ($disableZip !== null) {
                $orchestrator->config->setDisableDigestZip($disableZip);
            }
            if ($scanMaxDepth !== null) {
                $orchestrator->config->setScanMaxDepth($scanMaxDepth);
            }

            $orchestrator->dispatch($subcommand, $isPurge, $isConfirm, $isAll, $isRunBuild, $targetId);
        } catch (\Throwable $e) {
            echo $e->getMessage() . "\n";
            exit(1);
        }
    }

    public function dispatch(?string $subcommand, bool $isPurge, bool $isConfirm, bool $isAll, bool $isRunBuild, ?string $targetId): void
    {
        if (empty($subcommand) || $subcommand === 'help') {
            $this->printHelp();
            return;
        }

        switch ($subcommand) {
            case 'pull':
                if ($isRunBuild) {
                    $this->acquireLock();
                }
                $action = new PullAction($this->config);
                $action->execute($isPurge, $isRunBuild);
                if ($isRunBuild) {
                    $this->releaseLock();
                }
                break;

            case 'eject':
                $isLive = $isConfirm || $isRunBuild;
                if ($isLive) {
                    $this->acquireLock();
                }
                $action = new EjectAction($this->config);
                $action->execute($isConfirm, $isRunBuild);
                if ($isLive) {
                    $this->releaseLock();
                }
                break;

            case 'commit':
                if ($isRunBuild) {
                    $this->acquireLock();
                }
                $action = new CommitAction($this->config);
                $action->execute($targetId, $isAll, $isRunBuild);
                if ($isRunBuild) {
                    $this->releaseLock();
                }
                break;

            case 'purge':
                if ($isRunBuild) {
                    $this->acquireLock();
                }
                $action = new PurgeAction($this->config);
                $action->execute($isRunBuild);
                if ($isRunBuild) {
                    $this->releaseLock();
                }
                break;

            case 'digest':
                if ($isRunBuild) {
                    $this->acquireLock();
                }
                $action = new DigestAction($this->config);
                $action->execute($isRunBuild);
                if ($isRunBuild) {
                    $this->releaseLock();
                }
                break;

            case 'status':
                $this->printStatus();
                break;

            default:
                echo "[!] Unknown subcommand: '{$subcommand}'. Run 'harvester' for available subcommands.\n";
                exit(1);
        }
    }

    private function printStatus(): void
    {
        $diagMsg = null;
        $destRoot = $this->config->resolveEffectiveDestinationRoot(true, $diagMsg);

        $sourceRoot  = $this->config->getSourceRoot();
        $sourceDrive = $this->config->getSourceDrive();
        $destDrive   = $this->config->getDestinationDrive();
        $fallbackOpt = $this->config->getUseDefaultFallback() ? 'TRUE' : 'FALSE';

        $stageDir     = $this->config->getPath('stage_dir');
        $specsDir     = $this->config->getPath('repo_items_dir');
        $ejectDir     = $this->config->getPath('eject_dir');
        $manifestFile = $this->config->getPath('spec_manifest');
        $ledgerFile   = $this->config->getPath('stage_ledger');

        $stagedCount  = $this->countDirectoryFiles($stageDir);
        $specsCount   = $this->countDirectoryFiles($specsDir);
        $ejectedCount = $this->countDirectoryFiles($ejectDir);

        $ledgerCount = 0;
        if (!empty($ledgerFile) && file_exists($ledgerFile)) {
            $ledgerCount = count(file($ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        $manifestCount = 0;
        if (!empty($manifestFile) && file_exists($manifestFile)) {
            $manifestCount = count(file($manifestFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        echo "========================================================\n";
        echo " Harvester Status Summary\n";
        echo "========================================================\n";

        echo " Source Root:       {$sourceRoot} [Drive: {$sourceDrive}]\n";
        echo " Destination Root:  " . ($destRoot ?: '(Unset / Offline)') . " [Drive: {$destDrive}]\n";
        echo " Default Fallback:  {$fallbackOpt}\n";
        if (!empty($diagMsg)) {
            echo " Diagnostic:        {$diagMsg}\n";
        }
        echo "--------------------------------------------------------\n";
        echo " Staged Items:      {$stagedCount} (Ledger: {$ledgerCount})\n";
        echo " Committed Specs:   {$specsCount} (Manifest: {$manifestCount})\n";
        echo " Ejected Items:     {$ejectedCount}\n";
        echo "========================================================\n";
    }

    private function countDirectoryFiles(string $dir): int
    {
        if (empty($dir) || !is_dir($dir)) return 0;
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
        if (empty($lockFile)) {
            throw new \RuntimeException("[CRITICAL ERROR] Lock file path could not be resolved. Destination is unconfigured or offline.");
        }

        $dir = dirname($lockFile);
        FileUtil::ensureDirectory($dir);

        $this->lockHandle = fopen($lockFile, 'c+');
        if (!$this->lockHandle || !flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            echo "[NOTICE] Another harvester process is currently active on this repository.\n";
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
        echo " vat-harvester CLI Engine\n";
        echo "========================================================\n";
        echo " Usage: php harvester.php <subcommand> [flags]\n\n";
        echo " Available Subcommands:\n";
        echo "   pull      Scan, classify, and stage target items (preview by default)\n";
        echo "   commit    Promote staged items into repo manifest (--id=<ID> or --all)\n";
        echo "   eject     Antipattern ejection protocol (preview by default)\n";
        echo "   purge     Clear uncommitted staging items & reset ledger\n";
        echo "   digest    Generate spec markdown digest report & zip archive\n";
        echo "   status    Display configuration and repository state summary\n\n";
        echo " Global Flags:\n";
        echo "   --RunBuild            Execute live filesystem mutations (default is Dry-Run)\n";
        echo "   --DryRun              Force dry-run preview mode\n";
        echo "   --UseDefaultFallback  Allow fallback to install directory when destination is unset\n";
        echo "   --config=<path>       Specify custom JSON configuration profile\n";
        echo "   --purge               Purge staging area prior to scanning (pull only)\n";
        echo "   --all                 Target all items in staging queue (commit only)\n";
        echo "   --id=<ID>             Target specific 8-character Hash ID (commit only)\n";
        echo "   --select-latest       Retain only newest spec per directory/stem (pull only)\n";
        echo "   --disable-zip         Bypass ZipArchive packaging; output open digest only (digest only)\n";
        echo "   --scan-max-depth=<N>  Limit directory scanning recursion depth (0 = immediate folder only)\n";
        echo "========================================================\n";
    }
}
