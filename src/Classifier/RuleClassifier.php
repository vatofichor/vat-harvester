<?php

namespace VatoHarvester\Classifier;

use VatoHarvester\Config\ConfigLoader;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class RuleClassifier
{
    public const STATE_IMITM = 'imitm'; // Primary item match (e.g. _spec.ext)
    public const STATE_EXITM = 'exitm'; // Antipattern candidate
    public const STATE_NNITM = 'nnitm'; // Non-matching file in target directory
    public const STATE_STITM = 'stitm'; // Staged item

    private ConfigLoader $config;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
    }

    /**
     * Classify a file by path against defined regex rules.
     *
     * @param string $filePath Absolute or relative path to file
     * @return string Classification state constant
     */
    public function classify(string $filePath): string
    {
        $filename = basename($filePath);

        // Check for antipattern ejection match first
        $antipatternRegex = $this->config->getRule('antipattern_regex');
        if (!empty($antipatternRegex) && preg_match($antipatternRegex, $filename)) {
            return self::STATE_EXITM;
        }

        // Check for primary item match
        $primaryRegex = $this->config->getRule('primary_spec_regex');
        if (!empty($primaryRegex) && preg_match($primaryRegex, $filename)) {
            return self::STATE_IMITM;
        }

        return self::STATE_NNITM;
    }
}
