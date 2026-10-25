<?php
/**
 * Test: Latest File Deduplication Engine (select_latest_only)
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

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Actions\PullAction;

echo "=== Running Latest-File Deduplication Validation ===\n";

$configFile = __DIR__ . '/testing.config.json';
$config = new ConfigLoader($configFile);

$testDir = __DIR__ . '/source/dedup_test';
if (!is_dir($testDir)) {
    mkdir($testDir, 0777, true);
}

// Create 3 historical versioned files with sequential modification times
$file1 = $testDir . '/backup_20260101-spec.txt';
$file2 = $testDir . '/backup_20260201-spec.txt';
$file3 = $testDir . '/backup_20260301-spec.txt';

file_put_contents($file1, "Backup 2026-01-01\n");
touch($file1, time() - 300);

file_put_contents($file2, "Backup 2026-02-01\n");
touch($file2, time() - 200);

file_put_contents($file3, "Backup 2026-03-01\n");
touch($file3, time() - 100);

// Point config to dedup_test
$config->setSourceSubdirs(['.']);
$config->setScanMaxDepth(0);

// Test 1: select_latest_only = false (Should stage all 3)
$config->setSelectLatestOnly(false);
$pullAction1 = new PullAction($config);

ob_start();
$res1 = $pullAction1->execute(false, false); // Dry run
$out1 = ob_get_clean();

echo "Test 1 (select_latest_only: false): ";
if ($res1['preview'] >= 3 && $res1['deduped'] === 0) {
    echo "PASS (staged all {$res1['preview']} files, deduped=0)\n";
} else {
    echo "FAIL (expected >=3 preview, got {$res1['preview']})\n";
    exit(1);
}

// Test 2: select_latest_only = true, latest_grouping = 'directory' (Should stage only file3)
$config->setSelectLatestOnly(true);
$config->setLatestGrouping('directory');
$pullAction2 = new PullAction($config);

ob_start();
$res2 = $pullAction2->execute(false, false); // Dry run
$out2 = ob_get_clean();

echo "Test 2 (select_latest_only: true, grouping: directory): ";
$hasFile3 = str_contains($out2, 'backup_20260301-spec.txt');
$hasFile1 = str_contains($out2, 'backup_20260101-spec.txt');

if ($hasFile3 && !$hasFile1 && $res2['deduped'] >= 2) {
    echo "PASS (selected newest candidate, filtered {$res2['deduped']} older items)\n";
} else {
    echo "FAIL (expected file3 only; hasFile3=" . ($hasFile3 ? '1' : '0') . ", hasFile1=" . ($hasFile1 ? '1' : '0') . ")\n";
    exit(1);
}

// Clean up test files
@unlink($file1);
@unlink($file2);
@unlink($file3);
@rmdir($testDir);

echo "=== All Latest-File Deduplication Tests Passed Cleanly ===\n";
