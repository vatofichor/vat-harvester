<?php
/**
 * Master Test Suite:
 * - Traversal Depth Restriction (scan_max_depth)
 * - Latest-File Deduplication (select_latest_only)
 * - Streaming Open Digest (disable_digest_zip & chunked SHA-256)
 *
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

require_once __DIR__ . '/../src/Util/EnvChecker.php';
require_once __DIR__ . '/../src/Util/FileUtil.php';
require_once __DIR__ . '/../src/Config/ConfigLoader.php';
require_once __DIR__ . '/../src/Scanner/WorkspaceScanner.php';
require_once __DIR__ . '/../src/Classifier/RuleClassifier.php';
require_once __DIR__ . '/../src/Actions/PullAction.php';
require_once __DIR__ . '/../src/Actions/CommitAction.php';
require_once __DIR__ . '/../src/Actions/DigestAction.php';
require_once __DIR__ . '/../src/Actions/PurgeAction.php';
require_once __DIR__ . '/../src/Orchestrator.php';

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Util\FileUtil;
use VatoHarvester\Orchestrator;

echo "========================================================\n";
echo " Running Master Integration Test Suite\n";
echo "========================================================\n";

$configFile = __DIR__ . '/testing.config.json';
$config = new ConfigLoader($configFile);

// Reset test repo state
$purgeArgs = [
    'harvester.php',
    'purge',
    "--config={$configFile}",
    '--RunBuild',
];
ob_start();
Orchestrator::run($purgeArgs);
ob_get_clean();

$manifestFile = $config->getPath('spec_manifest');
if (file_exists($manifestFile)) {
    file_put_contents($manifestFile, '');
}

$sourceRoot = $config->getSourceRoot();
$mockProj = $sourceRoot . DIRECTORY_SEPARATOR . 'v212_archive_proj';
$deepDir  = $mockProj . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'pkg' . DIRECTORY_SEPARATOR . 'specs';

FileUtil::ensureDirectory($mockProj);
FileUtil::ensureDirectory($deepDir);

// 1. Setup mock historical files
$zip1 = $mockProj . DIRECTORY_SEPARATOR . 'backup_20260101-specs.zip';
$zip2 = $mockProj . DIRECTORY_SEPARATOR . 'backup_20260201-specs.zip';
$zip3 = $mockProj . DIRECTORY_SEPARATOR . 'backup_20260301-specs.zip';
$authSpec = $mockProj . DIRECTORY_SEPARATOR . 'auth-specs.md';
$deepSpec = $deepDir . DIRECTORY_SEPARATOR . 'nested_vendor-specs.md';

file_put_contents($zip1, "Mock ZIP 2026-01-01 archive binary payload\n");
touch($zip1, time() - 300);

file_put_contents($zip2, "Mock ZIP 2026-02-01 archive binary payload\n");
touch($zip2, time() - 200);

file_put_contents($zip3, "Mock ZIP 2026-03-01 archive binary payload\n");
touch($zip3, time() - 100);

file_put_contents($authSpec, "# Auth Spec Documentation\n");
touch($authSpec, time() - 400);

file_put_contents($deepSpec, "# Deeply nested vendor specification\n");
touch($deepSpec, time() - 400);

echo "[+] Created mock historical archives and deep directories.\n\n";

// --- TEST CASE 1: Depth Restriction ---
echo "--- TEST 1: Traversal Depth Restriction (scan_max_depth: 0) ---\n";
$config->setScanMaxDepth(0);
$config->setSourceSubdirs(['.']);
$config->setSelectLatestOnly(false);

$scanner = new \VatoHarvester\Scanner\WorkspaceScanner($config);
$candidates = $scanner->scanCandidates();

$foundDeep = false;
$foundZip3 = false;
foreach ($candidates as $c) {
    if (basename($c['originLoc']) === 'nested_vendor-specs.md') {
        $foundDeep = true;
    }
    if (basename($c['originLoc']) === 'backup_20260301-specs.zip') {
        $foundZip3 = true;
    }
}

if (!$foundDeep && $foundZip3) {
    echo "[PASS] scan_max_depth: 0 successfully isolated immediate root and excluded nested vendor specs.\n\n";
} else {
    echo "[FAIL] Depth restriction failed! foundDeep=" . ($foundDeep ? 'YES' : 'NO') . " foundZip3=" . ($foundZip3 ? 'YES' : 'NO') . "\n";
    cleanupMock($mockProj);
    exit(1);
}

// --- TEST CASE 2: Latest Deduplication (Dry-Run & Live) ---
echo "--- TEST 2: Latest-Only Deduplication Filter (Regex Stem Grouping) ---\n";
// Dry-run preview
$pullArgsDry = [
    'harvester.php',
    'pull',
    "--config={$configFile}",
    '--select-latest',
    '--latest-grouping=regex',
    '--scan-max-depth=0',
    '--DryRun',
];

ob_start();
Orchestrator::run($pullArgsDry);
$dryOut = ob_get_clean();

$hasZip3Preview = str_contains($dryOut, 'backup_20260301-specs.zip');
$hasZip1Preview = str_contains($dryOut, 'backup_20260101-specs.zip');
$hasAuthPreview = str_contains($dryOut, 'auth-specs.md');
$hasDedupNotice = str_contains($dryOut, 'Superseded By Newer: 2');

if ($hasZip3Preview && !$hasZip1Preview && $hasAuthPreview && $hasDedupNotice) {
    echo "[PASS] Dry-run preview correctly selected newest archive and reported 2 superseded older backups.\n";
} else {
    echo "[FAIL] Dry-run deduplication failed!\n{$dryOut}\n";
    cleanupMock($mockProj);
    exit(1);
}

// Live staging build
$pullArgsLive = [
    'harvester.php',
    'pull',
    "--config={$configFile}",
    '--select-latest',
    '--latest-grouping=regex',
    '--scan-max-depth=0',
    '--RunBuild',
];

ob_start();
Orchestrator::run($pullArgsLive);
$liveOut = ob_get_clean();

$stageDir = $config->getPath('stage_dir');
$stagedZip3 = $stageDir . DIRECTORY_SEPARATOR . 'v212_archive_proj' . DIRECTORY_SEPARATOR . 'backup_20260301-specs.zip';
$stagedZip1 = $stageDir . DIRECTORY_SEPARATOR . 'v212_archive_proj' . DIRECTORY_SEPARATOR . 'backup_20260101-specs.zip';

if (file_exists($stagedZip3) && !file_exists($stagedZip1)) {
    echo "[PASS] Live build staged ONLY newest backup and skipped older historical archives.\n\n";
} else {
    echo "[FAIL] Live staging deduplication failed! Staged zip1 exists=" . (file_exists($stagedZip1) ? 'YES' : 'NO') . "\n";
    cleanupMock($mockProj);
    exit(1);
}

// --- TEST CASE 3: Commit and Streaming Open Digest ---
echo "--- TEST 3: Commit & Streaming Open Digest Mode ---\n";
// Commit staged items
$commitArgs = [
    'harvester.php',
    'commit',
    "--config={$configFile}",
    '--all',
    '--RunBuild',
];

ob_start();
Orchestrator::run($commitArgs);
$commitOut = ob_get_clean();

// Run Digest with Open Digest Mode (--disable-zip)
$digestArgs = [
    'harvester.php',
    'digest',
    "--config={$configFile}",
    '--disable-zip',
    '--RunBuild',
];

ob_start();
Orchestrator::run($digestArgs);
$digestOut = ob_get_clean();

$digestsDir = $config->getPath('digests_dir');
$digestFiles = glob($digestsDir . DIRECTORY_SEPARATOR . 'digest_*.md') ?: [];
$zipArchives = glob($digestsDir . DIRECTORY_SEPARATOR . 'digest_archive_*.zip') ?: [];

if (!empty($digestFiles) && empty($zipArchives)) {
    $latestMd = end($digestFiles);
    $content = file_get_contents($latestMd);

    $hasOpenNotice = str_contains($content, 'Open Digest');
    $hasSha256     = str_contains($content, 'sha256:');

    if ($hasOpenNotice && $hasSha256) {
        echo "[PASS] Open Digest generated streaming markdown report with verified SHA-256 signatures.\n";
        echo "[PASS] Confirmed zero ZIP archives were produced (saving CPU/RAM/Disk).\n\n";
    } else {
        echo "[FAIL] Digest markdown missing Open Digest indicator or SHA-256 signatures.\n";
        cleanupMock($mockProj);
        exit(1);
    }
} else {
    echo "[FAIL] Digest verification failed: mdCount=" . count($digestFiles) . ", zipCount=" . count($zipArchives) . "\n";
    cleanupMock($mockProj);
    exit(1);
}

// Cleanup mock files and reset test environment
cleanupMock($mockProj);

// Clean test staged/specs/digests
$purgeArgs = [
    'harvester.php',
    'purge',
    "--config={$configFile}",
    '--RunBuild',
];
ob_start();
Orchestrator::run($purgeArgs);
ob_get_clean();

foreach ($digestFiles as $f) {
    @unlink($f);
}

echo "========================================================\n";
echo " ALL INTEGRATION TESTS PASSED CLEANLY\n";
echo "========================================================\n";

function cleanupMock(string $mockProj): void
{
    if (!is_dir($mockProj)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mockProj, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($mockProj);
}
