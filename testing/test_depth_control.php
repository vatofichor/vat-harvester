<?php
/**
 * Test: Traversal Depth Control (scan_max_depth)
 * 
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

require_once __DIR__ . '/../src/Util/EnvChecker.php';
require_once __DIR__ . '/../src/Util/FileUtil.php';
require_once __DIR__ . '/../src/Config/ConfigLoader.php';
require_once __DIR__ . '/../src/Scanner/WorkspaceScanner.php';

use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Scanner\WorkspaceScanner;

echo "=== Running Traversal Depth Control Validation ===\n";

$configFile = __DIR__ . '/testing.config.json';
$config = new ConfigLoader($configFile);

// Ensure test directory structure: testing/source/1/level1/level2/nested-spec.txt
$deepDir = __DIR__ . '/source/1/deep_nesting/level2';
if (!is_dir($deepDir)) {
    mkdir($deepDir, 0777, true);
}
$deepSpecFile = $deepDir . '/deep-spec.txt';
file_put_contents($deepSpecFile, "Deeply nested spec item\n");

$rootSpecFile = __DIR__ . '/source/1/immediate-spec.txt';
file_put_contents($rootSpecFile, "Immediate root spec item\n");

// Test 1: Unlimited depth (default, scan_max_depth = null)
$config->setScanMaxDepth(null);
$config->setSourceSubdirs(['.']);
$scanner1 = new WorkspaceScanner($config);
$candidates1 = $scanner1->scanCandidates();

$foundDeep1 = false;
$foundRoot1 = false;
foreach ($candidates1 as $c) {
    if (basename($c['originLoc']) === 'deep-spec.txt') {
        $foundDeep1 = true;
    }
    if (basename($c['originLoc']) === 'immediate-spec.txt') {
        $foundRoot1 = true;
    }
}

echo "Test 1 (Unlimited Depth): ";
if ($foundDeep1 && $foundRoot1) {
    echo "PASS (found both immediate and deeply nested files)\n";
} else {
    echo "FAIL (expected deep and root files; deep=" . ($foundDeep1 ? '1' : '0') . " root=" . ($foundRoot1 ? '1' : '0') . ")\n";
    exit(1);
}

// Test 2: Shallow depth (scan_max_depth = 0)
$config->setScanMaxDepth(0);
$scanner2 = new WorkspaceScanner($config);
$candidates2 = $scanner2->scanCandidates();

$foundDeep2 = false;
$foundRoot2 = false;
foreach ($candidates2 as $c) {
    if (basename($c['originLoc']) === 'deep-spec.txt') {
        $foundDeep2 = true;
    }
    if (basename($c['originLoc']) === 'immediate-spec.txt') {
        $foundRoot2 = true;
    }
}

echo "Test 2 (scan_max_depth: 0): ";
if (!$foundDeep2 && $foundRoot2) {
    echo "PASS (found immediate file, successfully excluded nested subdirectories)\n";
} else {
    echo "FAIL (expected immediate only; deep=" . ($foundDeep2 ? 'found (SHOULD NOT BE FOUND)' : 'excluded') . " root=" . ($foundRoot2 ? 'found' : 'missing') . ")\n";
    exit(1);
}

// Clean up temporary test files
if (file_exists($deepSpecFile)) {
    unlink($deepSpecFile);
}
if (is_dir($deepDir)) {
    rmdir($deepDir);
}
$parentDeep = dirname($deepDir);
if (is_dir($parentDeep)) {
    rmdir($parentDeep);
}
if (file_exists($rootSpecFile)) {
    unlink($rootSpecFile);
}

echo "=== All Traversal Depth Control Tests Passed Cleanly ===\n";
