<?php
/**
 * # Copyright (c) 2026:
 * # vatofichor - Sebastian Mass     [>_<]
 * # & Assisted By Gemini Antigravity /|\
 * 
 * Regression & Verification Test Suite for GUI JSON Error Resilience & Engine Decoupling
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Util/EnvChecker.php';
require_once __DIR__ . '/../src/Util/FileUtil.php';
require_once __DIR__ . '/../src/Config/ConfigLoader.php';
require_once __DIR__ . '/../src/Scanner/WorkspaceScanner.php';
require_once __DIR__ . '/../src/Classifier/RuleClassifier.php';
require_once __DIR__ . '/../src/Actions/PullAction.php';
require_once __DIR__ . '/../src/Actions/EjectAction.php';
require_once __DIR__ . '/../src/Actions/CommitAction.php';
require_once __DIR__ . '/../src/Actions/PurgeAction.php';
require_once __DIR__ . '/../src/Actions/DigestAction.php';
require_once __DIR__ . '/../src/Orchestrator.php';

use VatoHarvester\Orchestrator;

echo "========================================================\n";
echo " Running Regression Tests: GUI JSON Resilience & Decoupling\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $condition, string $message): void
{
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] {$message}\n";
        $passCount++;
    } else {
        echo "[FAIL] {$message}\n";
        $failCount++;
    }
}

// 1. Verify Orchestrator throws \InvalidArgumentException on invalid command instead of calling exit(1)
try {
    $caught = false;
    try {
        Orchestrator::run(['harvester.php', 'nonexistent_command_for_test']);
    } catch (\Throwable $e) {
        $caught = true;
        assertCondition($e instanceof \InvalidArgumentException, "Orchestrator::run throws \InvalidArgumentException for invalid subcommand");
        assertCondition(str_contains($e->getMessage(), "Unknown subcommand"), "Exception contains informative unknown subcommand message");
    }
    assertCondition($caught, "Exception cleanly bubbled up to caller without process exit");
} catch (\Throwable $e) {
    assertCondition(false, "Unexpected failure during test 1: " . $e->getMessage());
}

// 2. Verify CLI entrypoint harvester.php exits with status 1 on error and status 0 on success
$cliStatusOutput = shell_exec("php " . escapeshellarg(__DIR__ . '/../harvester.php') . " status");
assertCondition(!empty($cliStatusOutput) && str_contains($cliStatusOutput, "Harvester Status Summary"), "CLI harvester.php status returns valid output");

exec("php " . escapeshellarg(__DIR__ . '/../harvester.php') . " nonexistent_subcommand 2>&1", $errOutput, $exitCode);
assertCondition($exitCode === 1, "CLI harvester.php nonexistent_subcommand yields process exit code 1");
assertCondition(implode("\n", $errOutput) !== '' && str_contains(implode("\n", $errOutput), "Unknown subcommand"), "CLI error output is displayed on stderr/stdout");

// 3. Verify GUI AJAX simulated execution captures error and outputs valid JSON envelope with success: false
$simScript = __DIR__ . '/sim_gui_request.php';
$payloadEncoded = base64_encode('{"action":"status","config":"nonexistent_file_test.json"}');
$guiOutput = shell_exec('php ' . escapeshellarg($simScript) . ' ' . escapeshellarg($payloadEncoded));

$decoded = json_decode((string)$guiOutput, true);
assertCondition(is_array($decoded), "Simulated GUI AJAX dispatch yields valid, parseable JSON envelope");
if (is_array($decoded)) {
    assertCondition(isset($decoded['success']) && $decoded['success'] === false, "JSON envelope contains success: false on engine error");
    assertCondition(isset($decoded['output']) && str_contains($decoded['output'], "[ENGINE ERROR]"), "JSON output contains captured [ENGINE ERROR]");
}

// 4. Verify GUI dynamic config discovery includes testing configs and default configs
$guiHtml = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/gui/index.php'));
assertCondition(str_contains((string)$guiHtml, 'value="harvester.config.json"'), "GUI renders default harvester.config.json option");
assertCondition(str_contains((string)$guiHtml, 'value="config/default.config.json"'), "GUI renders config/default.config.json option");
assertCondition(str_contains((string)$guiHtml, 'value="testing/testing.config.json"'), "GUI dynamically discovers and renders testing.config.json option");

echo "\n--------------------------------------------------------\n";
echo " Summary: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
