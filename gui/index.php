<?php
/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 *
 * Simplex Web GUI Dashboard & Engine Router (:8091)
 */

// Route static files directly when running under PHP built-in server
if (php_sapi_name() === 'cli-server') {
    $uriPath  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $filePath = __DIR__ . $uriPath;
    if ($uriPath !== '/' && $uriPath !== '' && file_exists($filePath) && !is_dir($filePath)) {
        return false;
    }
}

$workspaceRoot = dirname(__DIR__);

require_once $workspaceRoot . '/src/Util/EnvChecker.php';

use VatoHarvester\Util\EnvChecker;

// Hard pre-flight runtime assertion
EnvChecker::assertEnvironment();

require_once $workspaceRoot . '/src/Util/FileUtil.php';
require_once $workspaceRoot . '/src/Config/ConfigLoader.php';
require_once $workspaceRoot . '/src/Scanner/WorkspaceScanner.php';
require_once $workspaceRoot . '/src/Classifier/RuleClassifier.php';
require_once $workspaceRoot . '/src/Actions/PullAction.php';
require_once $workspaceRoot . '/src/Actions/EjectAction.php';
require_once $workspaceRoot . '/src/Actions/CommitAction.php';
require_once $workspaceRoot . '/src/Actions/PurgeAction.php';
require_once $workspaceRoot . '/src/Actions/DigestAction.php';
require_once $workspaceRoot . '/src/Orchestrator.php';

use VatoHarvester\Orchestrator;
use VatoHarvester\Config\ConfigLoader;
use VatoHarvester\Util\FileUtil;

// Discover available config files
$availableConfigs = [];
$candidateConfigFiles = [
    'harvester.config.json',
    'config/default.config.json',
    'testing/testing.config.json',
    'testing/testing.isolated_logs.config.json',
];

foreach ($candidateConfigFiles as $rel) {
    if (file_exists($workspaceRoot . DIRECTORY_SEPARATOR . $rel)) {
        $availableConfigs[] = $rel;
    }
}

// Handle Action Dispatching (POST / AJAX)
$isAjax = isset($_GET['api']) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

$executedOutput = '';
$executedAction = '';

$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST') {

    $payload = [];
    if ($isAjax) {
        $rawJson = file_get_contents('php://input');
        $payload = json_decode($rawJson, true) ?: [];
    } else {
        $payload = $_POST;
    }

    $action          = trim((string)($payload['action'] ?? 'status'));
    $runBuild        = !empty($payload['runBuild']);
    $dryRun          = !empty($payload['dryRun']);
    $defaultFallback = !empty($payload['defaultFallback']);
    $allFlag         = !empty($payload['all']);
    $purgeFlag       = !empty($payload['purge']);
    $selectLatest    = !empty($payload['selectLatest']);
    $disableZip      = !empty($payload['disableZip']);
    $scanMaxDepth    = isset($payload['scanMaxDepth']) && $payload['scanMaxDepth'] !== '' ? (int)$payload['scanMaxDepth'] : null;
    $specId          = trim((string)($payload['specId'] ?? ''));
    $configPath      = trim((string)($payload['config'] ?? ''));

    // Construct CLI argument list
    $cliArgs = ['harvester.php', $action];

    if ($runBuild) {
        $cliArgs[] = '--RunBuild';
    }
    if ($dryRun) {
        $cliArgs[] = '--DryRun';
    }
    if ($defaultFallback) {
        $cliArgs[] = '--UseDefaultFallback';
    }
    if ($allFlag) {
        $cliArgs[] = '--all';
    }
    if ($purgeFlag) {
        $cliArgs[] = '--purge';
    }
    if ($selectLatest) {
        $cliArgs[] = '--select-latest';
    }
    if ($disableZip) {
        $cliArgs[] = '--disable-zip';
    }
    if ($scanMaxDepth !== null) {
        $cliArgs[] = "--scan-max-depth={$scanMaxDepth}";
    }
    if (!empty($specId)) {
        $cliArgs[] = "--id={$specId}";
    }
    if (!empty($configPath)) {
        $cliArgs[] = "--config={$configPath}";
    }

    // Capture execution buffer
    ob_start();
    try {
        Orchestrator::run($cliArgs);
    } catch (\Throwable $e) {
        echo "[FATAL ENGINE ERROR] " . $e->getMessage() . "\n";
    }
    $rawOutput = ob_get_clean();

    // Strip ANSI color escape codes for clean web display
    $cleanOutput = preg_replace('/\033\[[0-9;]*m/', '', $rawOutput);

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'action'  => $action,
            'output'  => $cleanOutput,
        ]);
        exit;
    }

    $executedOutput = $cleanOutput;
    $executedAction = $action;
}

// Initial status load for dashboard display
$initialOutput = $executedOutput;
if (empty($initialOutput)) {
    ob_start();
    try {
        Orchestrator::run(['harvester.php', 'status']);
    } catch (\Throwable $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
    }
    $initialOutput = preg_replace('/\033\[[0-9;]*m/', '', ob_get_clean());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>vat-harvester | Simplex GUI</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

    <!-- Master Header Strip -->
    <header class="app-header">
        <div class="brand-title">
            <span>vat-harvester</span>
        </div>
        <div class="header-status">
            <span><span class="status-dot"></span> Port :8091</span>
            <span>PHP <?= htmlspecialchars(PHP_VERSION) ?></span>
            <span>Localhost GUI</span>
        </div>
    </header>

    <!-- Main Workspace Grid -->
    <main class="workspace-grid">

        <!-- Left Command & Control Panel -->
        <aside class="panel-control">
            <form id="harvesterForm" method="POST" action="">

                <!-- Actions Launch Section -->
                <div class="control-section">
                    <div class="section-header">
                        <span>Actions</span>
                    </div>
                    <div class="section-body">
                        <div class="actions-grid">
                            <button type="submit" name="action" value="status" class="btn-action action-status" data-action="status">STATUS (Inspect)</button>
                            <button type="submit" name="action" value="pull" class="btn-action action-pull" data-action="pull">PULL</button>
                            <button type="submit" name="action" value="commit" class="btn-action action-commit" data-action="commit">COMMIT</button>
                            <button type="submit" name="action" value="digest" class="btn-action action-digest" data-action="digest">DIGEST</button>
                            <button type="submit" name="action" value="eject" class="btn-action action-eject" data-action="eject">EJECT</button>
                            <button type="submit" name="action" value="purge" class="btn-action action-purge" data-action="purge">PURGE</button>
                        </div>
                    </div>
                </div>

                <!-- Execution Modifiers Section -->
                <div class="control-section">
                    <div class="section-header">
                        <span>Execution Modifiers</span>
                    </div>
                    <div class="section-body">
                        <label class="flag-row flag-runbuild">
                            <input type="checkbox" name="runBuild" id="flagRunBuild" value="1">
                            <span>--RunBuild (LIVE BUILD)</span>
                        </label>
                        <label class="flag-row">
                            <input type="checkbox" name="dryRun" id="flagDryRun" value="1" checked>
                            <span>--DryRun (Preview Only)</span>
                        </label>
                        <label class="flag-row">
                            <input type="checkbox" name="defaultFallback" id="flagDefaultFallback" value="1">
                            <span>--UseDefaultFallback</span>
                        </label>
                        <label class="flag-row">
                            <input type="checkbox" name="all" id="flagAll" value="1">
                            <span>--all (Commit All)</span>
                        </label>
                        <label class="flag-row">
                            <input type="checkbox" name="purge" id="flagPurge" value="1">
                            <span>--purge (Clean Staging)</span>
                        </label>
                        <label class="flag-row">
                            <input type="checkbox" name="selectLatest" id="flagSelectLatest" value="1">
                            <span>--select-latest (Newest Only)</span>
                        </label>
                        <label class="flag-row">
                            <input type="checkbox" name="disableZip" id="flagDisableZip" value="1">
                            <span>--disable-zip (Open Digest)</span>
                        </label>
                    </div>
                </div>

                <!-- Configuration & Parameters Section -->
                <div class="control-section">
                    <div class="section-header">
                        <span>Parameters</span>
                    </div>
                    <div class="section-body">
                        <div class="field-group">
                            <label class="field-label" for="configSelect">Config Profile (--config)</label>
                            <select name="config" id="configSelect" class="field-select">
                                <option value="">Default (harvester.config.json)</option>
                                <?php foreach ($availableConfigs as $cfg): ?>
                                    <option value="<?= htmlspecialchars($cfg) ?>"><?= htmlspecialchars($cfg) ?></option>
                                <?php endforeach; ?>
                                <option value="__custom__">[ Custom Config Path... ]</option>
                            </select>
                            <input type="text" id="customConfigInput" placeholder="path/to/custom.config.json" class="field-input" style="display: none; margin-top: 4px;">
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="optSpecId">Target Spec ID (--id)</label>
                            <input type="text" name="specId" id="optSpecId" placeholder="e.g. 731b039e (optional)" class="field-input">
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="optScanMaxDepth">Max Scan Depth (--scan-max-depth)</label>
                            <input type="number" min="0" name="scanMaxDepth" id="optScanMaxDepth" placeholder="e.g. 0 (shallow root), blank for all" class="field-input">
                        </div>
                    </div>
                </div>

            </form>
        </aside>

        <!-- Right Terminal Console Panel -->
        <section class="panel-terminal">
            <div class="terminal-header">
                <div class="terminal-title">
                    <span>Execution Console</span>
                </div>
                <div class="terminal-actions">
                    <label class="flag-row" style="color: var(--text-muted); font-size: 11px;">
                        <input type="checkbox" id="chkAutoScroll" checked>
                        <span>Auto-Scroll</span>
                    </label>
                    <button type="button" id="btnClear" class="btn-terminal">Clear</button>
                </div>
            </div>
            <div id="terminalScreen" class="terminal-screen"><div class="log-entry"><div class="log-command">$ php harvester.php status</div><div class="log-output"><?= htmlspecialchars($initialOutput) ?></div></div></div>
        </section>

    </main>

    <!-- Footer Status Bar -->
    <footer class="app-footer">
        <div>
            <span>vat-harvester | Dashboard</span>
        </div>
        <div>
            <span>&copy; 2026 vatofichor &amp; Assisted By Gemini Antigravity [&gt;_&lt;]</span>
        </div>
    </footer>

    <script src="assets/app.js"></script>
</body>
</html>
