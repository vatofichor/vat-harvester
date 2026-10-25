<?php
/**
 * Test: Streaming Open Digest & Chunked SHA-256 Validation
 * 
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

require_once __DIR__ . '/../src/Util/EnvChecker.php';
require_once __DIR__ . '/../src/Util/FileUtil.php';
require_once __DIR__ . '/../src/Config/ConfigLoader.php';
require_once __DIR__ . '/../src/Scanner/WorkspaceScanner.php';
require_once __DIR__ . '/../src/Actions/DigestAction.php';

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Actions\DigestAction;

echo "=== Running Streaming Open Digest Validation ===\n";

$configFile = __DIR__ . '/testing.config.json';
$config = new ConfigLoader($configFile);

$specsDir = $config->getPath('repo_items_dir');
if (!is_dir($specsDir)) {
    mkdir($specsDir, 0777, true);
}

// Create sample committed spec files
$testFile1 = $specsDir . '/test_service-spec.txt';
file_put_contents($testFile1, "Service specification payload: 12345\n");
$expectedHash1 = hash('sha256', "Service specification payload: 12345\n");

$testFile2 = $specsDir . '/test_schema-specs.json';
file_put_contents($testFile2, "{\"table\": \"users\", \"columns\": [\"id\", \"email\"]}\n");
$expectedHash2 = hash('sha256', "{\"table\": \"users\", \"columns\": [\"id\", \"email\"]}\n");

$digestsDir = $config->getPath('digests_dir');

// Test 1: Open Digest Mode (disable_digest_zip: true)
$config->setDisableDigestZip(true);
$digestAction1 = new DigestAction($config);

ob_start();
$res1 = $digestAction1->execute(true); // Live build
$out1 = ob_get_clean();

echo "Test 1 (Open Digest - disable_digest_zip: true): ";
if ($res1['strategy'] === 'open-digest' && $res1['archive'] === null && file_exists($res1['markdown'])) {
    $mdContent = file_get_contents($res1['markdown']);
    $hasHash1 = str_contains($mdContent, "sha256:{$expectedHash1}");
    $hasHash2 = str_contains($mdContent, "sha256:{$expectedHash2}");
    if ($hasHash1 && $hasHash2) {
        echo "PASS (generated streaming markdown with verified SHA-256, bypassed ZipArchive)\n";
    } else {
        echo "FAIL (missing expected SHA-256 hashes in digest markdown)\n";
        exit(1);
    }
} else {
    echo "FAIL (expected open-digest strategy without archive)\n";
    exit(1);
}

// Test 2: Standard Zip Bundle Mode (disable_digest_zip: false, digest_mode: 'zip')
$config->setDisableDigestZip(false);
$config->setDigestMode('zip');
$digestAction2 = new DigestAction($config);

ob_start();
$res2 = $digestAction2->execute(true); // Live build
$out2 = ob_get_clean();

echo "Test 2 (Zip Archive Bundle - disable_digest_zip: false): ";
if (class_exists('ZipArchive')) {
    if ($res2['strategy'] === 'zip-bundle' && $res2['archive'] !== null && file_exists($res2['archive'])) {
        echo "PASS (generated both markdown and zip archive: " . basename($res2['archive']) . ")\n";
    } else {
        echo "FAIL (expected zip-bundle with valid zip file)\n";
        exit(1);
    }
} else {
    if ($res2['strategy'] === 'zip-bundle' && $res2['archive'] === null && file_exists($res2['markdown'])) {
        echo "PASS (ZipArchive extension not active; gracefully produced markdown report with archive=null)\n";
    } else {
        echo "FAIL (unexpected response when ZipArchive is absent)\n";
        exit(1);
    }
}

// Clean up test files
@unlink($testFile1);
@unlink($testFile2);
if (file_exists($res1['markdown'])) {
    @unlink($res1['markdown']);
}
if (file_exists($res2['markdown'])) {
    @unlink($res2['markdown']);
}
if ($res2['archive'] && file_exists($res2['archive'])) {
    @unlink($res2['archive']);
}

echo "=== All Streaming Open Digest Tests Passed Cleanly ===\n";
