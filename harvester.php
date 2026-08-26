<?php
/**
 * # Copyright (c) 2026:
 * # vatofichor - Sebastian Mass     [>_<]
 * # & Assisted By Gemini Antigravity /|\
 * 
 * Master CLI Entrypoint for vato-harvester Engine
 */

require_once __DIR__ . '/src/Config/ConfigLoader.php';
require_once __DIR__ . '/src/Scanner/WorkspaceScanner.php';
require_once __DIR__ . '/src/Classifier/RuleClassifier.php';
require_once __DIR__ . '/src/Actions/PullAction.php';
require_once __DIR__ . '/src/Actions/EjectAction.php';
require_once __DIR__ . '/src/Actions/CommitAction.php';
require_once __DIR__ . '/src/Actions/PurgeAction.php';
require_once __DIR__ . '/src/Actions/DigestAction.php';
require_once __DIR__ . '/src/Orchestrator.php';

use VatoHarvester\Orchestrator;

Orchestrator::run($argv);
