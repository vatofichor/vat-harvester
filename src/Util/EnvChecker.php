<?php

namespace VatoHarvester\Util;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class EnvChecker
{
    public const MIN_PHP_VERSION_ID = 80300;
    public const MIN_PHP_VERSION_STR = '8.3.0';

    /**
     * Assert PHP 8.3+ runtime requirement.
     * Halts execution fatally with code 1 if unmet.
     */
    public static function assertEnvironment(): void
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            $currentVersion = PHP_VERSION;
            $currentId      = PHP_VERSION_ID;
            $requiredStr    = self::MIN_PHP_VERSION_STR;

            $msg  = "\033[31m" . str_repeat('=', 64) . "\033[0m" . PHP_EOL;
            $msg .= "\033[31;1m[CRITICAL RUNTIME ERROR] Environment Prerequisite Unmet\033[0m" . PHP_EOL;
            $msg .= "\033[31m" . str_repeat('=', 64) . "\033[0m" . PHP_EOL;
            $msg .= "vat-harvester requires PHP {$requiredStr} or higher." . PHP_EOL;
            $msg .= "Detected runtime: PHP {$currentVersion} (PHP_VERSION_ID: {$currentId})" . PHP_EOL;
            $msg .= "Execution halted immediately." . PHP_EOL;
            $msg .= "\033[31m" . str_repeat('=', 64) . "\033[0m" . PHP_EOL;

            if (defined('STDERR') && is_resource(STDERR)) {
                fwrite(STDERR, $msg);
            } else {
                echo $msg;
            }
            exit(1);
        }
    }

    /**
     * Non-fatal boolean verification for dashboards or diagnostics.
     */
    public static function isCompatible(?string &$diagnosticMessage = null): bool
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            $diagnosticMessage = sprintf(
                "PHP %s or higher is required. Detected PHP %s (ID: %d).",
                self::MIN_PHP_VERSION_STR,
                PHP_VERSION,
                PHP_VERSION_ID
            );
            return false;
        }

        $diagnosticMessage = sprintf("PHP %s (Compatible)", PHP_VERSION);
        return true;
    }
}
