<?php

declare(strict_types=1);

namespace Tessera\Installer;

/**
 * User-level config persisted at ~/.tessera/config.json.
 *
 * Currently stores the AI plan-tier answers (claude=max, codex=plus, ...) so
 * `tessera new` only asks "What AI plans do you have?" once per machine. The
 * ~/.tessera directory already hosts the .notice-accepted first-run marker
 * (see NewCommand::showFirstRunNotice()).
 *
 * Reads tolerate a missing or corrupt file by returning an empty config — the
 * caller falls back to prompting rather than crashing. Writes are atomic using
 * the same lock + tmp + rename pattern as Memory, so a crash mid-write never
 * leaves a half-written config.json.
 */
final class UserConfig
{
    private string $configDir;

    private string $configFile;

    /**
     * @param  string  $homeDir  User home directory (the parent of .tessera).
     */
    public function __construct(string $homeDir)
    {
        $this->configDir = $homeDir.DIRECTORY_SEPARATOR.'.tessera';
        $this->configFile = $this->configDir.DIRECTORY_SEPARATOR.'config.json';
    }

    /**
     * Resolve the user home directory the same way showFirstRunNotice() does.
     *
     * Returns null when neither HOME nor USERPROFILE is set — callers must
     * treat that as "no persistence available" and fall back to prompting.
     */
    public static function forCurrentUser(): ?self
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';

        if ($home === '') {
            return null;
        }

        return new self($home);
    }

    /**
     * Load saved AI plans (tool => plan name). Returns an empty array when the
     * file is missing, unreadable, or contains invalid/corrupt JSON.
     *
     * @return array<string, string>
     */
    public function loadPlans(): array
    {
        if (! is_file($this->configFile)) {
            return [];
        }

        $content = file_get_contents($this->configFile);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return [];
        }

        $plans = $decoded['plans'] ?? null;
        if (! is_array($plans)) {
            return [];
        }

        // Keep only string => string entries — anything else is corrupt.
        $clean = [];
        foreach ($plans as $tool => $plan) {
            if (is_string($tool) && is_string($plan) && $tool !== '' && $plan !== '') {
                $clean[$tool] = $plan;
            }
        }

        return $clean;
    }

    /**
     * Persist AI plans (tool => plan name), merging into any other config keys
     * already present in the file. Atomic write — never leaves a partial file.
     *
     * @param  array<string, string>  $plans
     */
    public function savePlans(array $plans): void
    {
        $config = $this->loadRaw();
        $config['plans'] = $plans;

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        if (! is_dir($this->configDir) && ! mkdir($this->configDir, 0755, true) && ! is_dir($this->configDir)) {
            return;
        }

        $this->writeAtomicLocked($json);
    }

    /**
     * Load the full decoded config as an array (empty on missing/corrupt).
     *
     * @return array<string, mixed>
     */
    private function loadRaw(): array
    {
        if (! is_file($this->configFile)) {
            return [];
        }

        $content = file_get_contents($this->configFile);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Atomic write with a cross-process lock — mirrors Memory::save().
     */
    private function writeAtomicLocked(string $json): void
    {
        $lockPath = $this->configFile.'.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if ($lockHandle === false) {
            // Can't create the lockfile — fall back to best-effort atomic write.
            $this->writeAtomic($json);

            return;
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                $this->writeAtomic($json);

                return;
            }

            $this->writeAtomic($json);

            flock($lockHandle, LOCK_UN);
        } finally {
            fclose($lockHandle);
        }
    }

    private function writeAtomic(string $json): void
    {
        $tmpFile = $this->configFile.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($tmpFile, $json) === false) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($this->configFile)) {
            // Windows rename() refuses to overwrite; remove first under the lock.
            // A crash window where config.json is missing is recoverable (load
            // returns empty → prompt), unlike a corrupt config.json.
            @unlink($this->configFile);
        }

        if (! @rename($tmpFile, $this->configFile)) {
            if (@copy($tmpFile, $this->configFile)) {
                @unlink($tmpFile);
            }
        }
    }
}
