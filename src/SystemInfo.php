<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * Detects OS, package managers, shells, and installed tools.
 * Provides system context for AI prompts so AI knows how to install things.
 */
final class SystemInfo
{
    private static ?self $instance = null;

    private string $os;

    private string $osVersion;

    private string $arch;

    private string $shell;

    /** @var array<string, string> */
    private array $packageManagers;

    /** @var array<string, string> */
    private array $installedTools;

    private function __construct()
    {
        $this->detectOs();
        $this->detectShell();
        $this->detectPackageManagers();
        $this->detectInstalledTools();
    }

    public static function detect(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function os(): string
    {
        return $this->os;
    }

    public function isWindows(): bool
    {
        return $this->os === 'windows';
    }

    public function isMac(): bool
    {
        return $this->os === 'macos';
    }

    public function isLinux(): bool
    {
        return $this->os === 'linux';
    }

    public function isWsl(): bool
    {
        return $this->os === 'wsl';
    }

    /**
     * Build a system context block for AI prompts.
     * AI uses this to know what OS it's on, what's installed, and how to install things.
     */
    public function buildAiContext(): string
    {
        $os = strtoupper($this->os);
        $tools = $this->formatInstalledTools();
        $pkgManagers = $this->formatPackageManagers();
        $installGuide = $this->buildInstallGuide();

        return <<<CONTEXT
SYSTEM ENVIRONMENT:
- OS: {$os} ({$this->osVersion})
- Architecture: {$this->arch}
- Shell: {$this->shell}
- Package managers: {$pkgManagers}
- Installed tools: {$tools}

INSTALL GUIDE FOR THIS OS:
{$installGuide}

RULES:
- Use commands appropriate for {$os}
- Use the correct package manager for this OS
- Use forward slashes for paths (even on Windows if running in Git Bash/MSYS)
- If a tool is missing, install it using the guide above before proceeding
CONTEXT;
    }

    /**
     * Check if a specific tool is installed.
     */
    public function hasTool(string $tool): bool
    {
        return isset($this->installedTools[$tool]);
    }

    /**
     * Get version of an installed tool.
     */
    public function toolVersion(string $tool): ?string
    {
        return $this->installedTools[$tool] ?? null;
    }

    /**
     * Get all installed tools.
     *
     * @return array<string, string>
     */
    public function tools(): array
    {
        return $this->installedTools;
    }

    /**
     * Get the best package manager for this OS.
     */
    public function packageManager(): string
    {
        if ($this->isWindows()) {
            return $this->packageManagers['scoop'] ?? $this->packageManagers['choco'] ?? $this->packageManagers['winget'] ?? 'manual';
        }

        if ($this->isMac()) {
            return $this->packageManagers['brew'] ?? 'manual';
        }

        // Linux/WSL
        return $this->packageManagers['apt'] ?? $this->packageManagers['dnf'] ?? $this->packageManagers['yum'] ?? $this->packageManagers['pacman'] ?? 'manual';
    }

    private function detectOs(): void
    {
        $this->arch = php_uname('m');

        if (PHP_OS_FAMILY === 'Windows') {
            // Check if running in WSL
            if (str_contains(php_uname('r'), 'microsoft') || str_contains(php_uname('r'), 'WSL')) {
                $this->os = 'wsl';
                $this->osVersion = trim((string) shell_exec('lsb_release -ds 2>/dev/null')) ?: php_uname('r');
            } else {
                $this->os = 'windows';
                // Check if MSYS/Git Bash
                $msysCheck = getenv('MSYSTEM');
                $this->osVersion = $msysCheck ? "Windows (MSYS2/{$msysCheck})" : 'Windows ' . php_uname('r');
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $this->os = 'macos';
            $this->osVersion = 'macOS ' . trim((string) shell_exec('sw_vers -productVersion 2>/dev/null'));
        } else {
            // Check WSL on Linux
            $procVersion = @file_get_contents('/proc/version');
            if ($procVersion !== false && (str_contains($procVersion, 'microsoft') || str_contains($procVersion, 'WSL'))) {
                $this->os = 'wsl';
            } else {
                $this->os = 'linux';
            }
            $this->osVersion = trim((string) shell_exec('lsb_release -ds 2>/dev/null')) ?: PHP_OS;
        }
    }

    private function detectShell(): void
    {
        $shell = getenv('SHELL');

        if ($shell) {
            $this->shell = basename($shell);

            return;
        }

        // Windows
        $comspec = getenv('COMSPEC');
        $msystem = getenv('MSYSTEM');

        if ($msystem) {
            $this->shell = 'bash (MSYS2)';
        } elseif ($comspec && str_contains(strtolower($comspec), 'powershell')) {
            $this->shell = 'powershell';
        } else {
            $this->shell = 'cmd';
        }
    }

    private function detectPackageManagers(): void
    {
        $this->packageManagers = [];

        $managers = [
            'brew' => 'brew --version',
            'apt' => 'apt --version',
            'dnf' => 'dnf --version',
            'yum' => 'yum --version',
            'pacman' => 'pacman --version',
            'scoop' => 'scoop --version',
            'choco' => 'choco --version',
            'winget' => 'winget --version',
            'snap' => 'snap --version',
        ];

        foreach ($managers as $name => $cmd) {
            $result = Console::execSilent($cmd);
            if ($result['exit'] === 0) {
                $version = trim(strtok($result['output'], "\n") ?: '');
                $this->packageManagers[$name] = $version;
            }
        }
    }

    private function detectInstalledTools(): void
    {
        $this->installedTools = [];

        $tools = [
            'php' => 'php --version',
            'composer' => 'composer --version',
            'node' => 'node --version',
            'npm' => 'npm --version',
            'go' => 'go version',
            'flutter' => 'flutter --version',
            'dart' => 'dart --version',
            'git' => 'git --version',
            'docker' => 'docker --version',
            'python' => 'python3 --version',
            'ruby' => 'ruby --version',
            'java' => 'java --version',
            'cargo' => 'cargo --version',
        ];

        foreach ($tools as $name => $cmd) {
            $result = Console::execSilent($cmd);
            if ($result['exit'] === 0) {
                $firstLine = trim(strtok($result['output'], "\n") ?: '');
                $this->installedTools[$name] = $firstLine;
            }
        }
    }

    private function formatInstalledTools(): string
    {
        if (empty($this->installedTools)) {
            return 'none detected';
        }

        $parts = [];
        foreach ($this->installedTools as $name => $version) {
            $parts[] = "{$name} ({$version})";
        }

        return implode(', ', $parts);
    }

    private function formatPackageManagers(): string
    {
        if (empty($this->packageManagers)) {
            return 'none detected';
        }

        return implode(', ', array_keys($this->packageManagers));
    }

    private function buildInstallGuide(): string
    {
        if ($this->isWindows()) {
            $mgr = isset($this->packageManagers['scoop']) ? 'scoop' : (isset($this->packageManagers['choco']) ? 'choco' : 'winget');

            return <<<GUIDE
Windows install commands (using {$mgr}):
- PHP: {$this->windowsInstallCmd($mgr, 'php')}
- Composer: {$this->windowsInstallCmd($mgr, 'composer')}
- Node.js: {$this->windowsInstallCmd($mgr, 'nodejs')}
- Go: {$this->windowsInstallCmd($mgr, 'go')}
- Flutter: {$this->windowsInstallCmd($mgr, 'flutter')}
- Git: {$this->windowsInstallCmd($mgr, 'git')}
- Docker: {$this->windowsInstallCmd($mgr, 'docker')}
GUIDE;
        }

        if ($this->isMac()) {
            return <<<'GUIDE'
macOS install commands (using brew):
- PHP: brew install php
- Composer: brew install composer
- Node.js: brew install node
- Go: brew install go
- Flutter: brew install --cask flutter
- Git: brew install git (usually pre-installed)
- Docker: brew install --cask docker
GUIDE;
        }

        if ($this->isWsl() || $this->isLinux()) {
            $mgr = isset($this->packageManagers['apt']) ? 'apt' : (isset($this->packageManagers['dnf']) ? 'dnf' : 'pacman');

            return $this->linuxInstallGuide($mgr);
        }

        return 'Manual installation required. Check official documentation for each tool.';
    }

    private function windowsInstallCmd(string $mgr, string $tool): string
    {
        $commands = [
            'scoop' => [
                'php' => 'scoop install php',
                'composer' => 'scoop install composer',
                'nodejs' => 'scoop install nodejs',
                'go' => 'scoop install go',
                'flutter' => 'scoop install flutter',
                'git' => 'scoop install git',
                'docker' => 'scoop install docker',
            ],
            'choco' => [
                'php' => 'choco install php',
                'composer' => 'choco install composer',
                'nodejs' => 'choco install nodejs',
                'go' => 'choco install golang',
                'flutter' => 'choco install flutter',
                'git' => 'choco install git',
                'docker' => 'choco install docker-desktop',
            ],
            'winget' => [
                'php' => 'winget install PHP.PHP',
                'composer' => 'winget install ComposerSetup.Composer',
                'nodejs' => 'winget install OpenJS.NodeJS',
                'go' => 'winget install GoLang.Go',
                'flutter' => 'Download from https://flutter.dev',
                'git' => 'winget install Git.Git',
                'docker' => 'winget install Docker.DockerDesktop',
            ],
        ];

        return $commands[$mgr][$tool] ?? "See official docs for {$tool}";
    }

    private function linuxInstallGuide(string $mgr): string
    {
        if ($mgr === 'apt') {
            return <<<'GUIDE'
Linux/WSL install commands (using apt):
- PHP: sudo apt install php php-cli php-mbstring php-xml php-curl php-zip
- Composer: curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
- Node.js: curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash - && sudo apt install nodejs
- Go: sudo apt install golang-go (or download from https://go.dev)
- Flutter: sudo snap install flutter --classic
- Git: sudo apt install git
- Docker: sudo apt install docker.io
GUIDE;
        }

        if ($mgr === 'dnf') {
            return <<<'GUIDE'
Linux install commands (using dnf):
- PHP: sudo dnf install php php-cli php-mbstring php-xml
- Composer: curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
- Node.js: sudo dnf install nodejs
- Go: sudo dnf install golang
- Git: sudo dnf install git
- Docker: sudo dnf install docker
GUIDE;
        }

        return <<<'GUIDE'
Linux install commands:
- PHP: Install via your distribution's package manager
- Composer: curl -sS https://getcomposer.org/installer | php
- Node.js: https://nodejs.org
- Go: https://go.dev
- Flutter: https://flutter.dev
- Git: Install via your distribution's package manager
GUIDE;
    }
}
