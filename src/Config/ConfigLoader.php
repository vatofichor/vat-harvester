<?php

namespace VatoHarvester\Config;

/**
 * Copyright (c) 2026:
 * vatofichor - Sebastian Mass     [>_<]
 * & Assisted By Gemini Antigravity /|\
 */

class ConfigLoader
{
    private string $workspaceRoot;
    private string $targetDrive = '';
    private string $targetRoot;
    private array $targetSubdirs = ['dev/specs', '.dev/specs', 'ai_persistent_files/dev/specs'];

    private array $paths = [
        'stage_dir'       => 'repo/stage',
        'repo_items_dir'  => 'repo/specs',
        'eject_dir'       => 'unspec',
        'stage_ledger'    => 'stage-ledger.txt',
        'spec_manifest'   => 'spec-manifest.txt',
        'lock_file'       => 'repo/.harvester.lock',
        'digests_dir'     => 'digests',
    ];

    private array $rules = [
        'primary_spec_regex' => '/[_-]specs?\\.[^.]+$/i',
        'antipattern_regex'   => '/(?:^|[-_.,0-9])(?:unspec|unitem|exitm)(?:$|[-_.,0-9])/i',
    ];


    public function __construct(?string $configPath = null, ?string $workspaceRoot = null)
    {
        $this->workspaceRoot = $workspaceRoot ? rtrim($workspaceRoot, '\\/') : getcwd();
        // Safe local test_repo default inside project root
        $this->targetRoot = $this->workspaceRoot . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'test_repo';
        $this->loadConfiguration($configPath);
    }

    /**
     * Dynamic JSON profile loader with cascade fallback order:
     * 1. Explicit $configPath flag argument
     * 2. harvester.config.json in workspace root
     * 3. config/default.config.json fallback
     */
    private function loadConfiguration(?string $configPath = null): void
    {
        $resolvedFile = null;

        if (!empty($configPath) && file_exists($configPath)) {
            $resolvedFile = $configPath;
        } elseif (file_exists($this->workspaceRoot . DIRECTORY_SEPARATOR . 'harvester.config.json')) {
            $resolvedFile = $this->workspaceRoot . DIRECTORY_SEPARATOR . 'harvester.config.json';
        } elseif (file_exists($this->workspaceRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'default.config.json')) {
            $resolvedFile = $this->workspaceRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'default.config.json';
        }

        if ($resolvedFile !== null) {
            $raw = file_get_contents($resolvedFile);
            $json = json_decode($raw, true);
            if (is_array($json)) {
                if (isset($json['target_drive'])) {
                    $this->targetDrive = (string)$json['target_drive'];
                }
                if (!empty($json['target_root'])) {
                    $targetRoot = $json['target_root'];
                    if (!preg_match('/^(?:[a-zA-Z]:|[\\\\\/])/', $targetRoot)) {
                        $targetRoot = $this->workspaceRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetRoot);
                    }
                    $this->targetRoot = $targetRoot;
                }
                if (!empty($json['target_subdirs']) && is_array($json['target_subdirs'])) {
                    $this->targetSubdirs = $json['target_subdirs'];
                }
                if (!empty($json['paths']) && is_array($json['paths'])) {
                    $this->paths = array_merge($this->paths, $json['paths']);
                }
                if (!empty($json['rules']) && is_array($json['rules'])) {
                    $this->rules = array_merge($this->rules, $json['rules']);
                }
            }
        }
    }

    public function getTargetDrive(): string
    {
        if (!empty($this->targetDrive)) {
            return $this->targetDrive;
        }
        if (preg_match('/^([a-zA-Z]:)/', $this->targetRoot, $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }

    public function setTargetDrive(string $drive): void
    {
        $this->targetDrive = $drive;
    }

    public function getTargetRoot(): string
    {
        return $this->targetRoot;
    }

    public function setTargetRoot(string $root): void
    {
        $this->targetRoot = $root;
    }

    public function getWorkspaceRoot(): string
    {
        return $this->workspaceRoot;
    }

    public function getTargetSubdirs(): array
    {
        return $this->targetSubdirs;
    }

    public function setTargetSubdirs(array $subdirs): void
    {
        $this->targetSubdirs = $subdirs;
    }

    public function getPath(string $key): string
    {
        $rel = $this->paths[$key] ?? '';
        return $this->workspaceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    public function getRelativePath(string $key): string
    {
        return $this->paths[$key] ?? '';
    }

    public function getRule(string $key): string
    {
        return $this->rules[$key] ?? '';
    }
}
