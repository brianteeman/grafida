<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Support;

/**
 * Resolves OS-appropriate, writable application directories.
 *
 * The application stores its SQLite database and any insecure secret fallback
 * inside the per-user application data directory for the current platform.
 */
final class Paths
{
    private const APP_DIR_NAME = 'Grafida';

    /**
     * Absolute path to the per-user application data directory.
     *
     * macOS:   ~/Library/Application Support/Grafida
     * Windows: %APPDATA%\Grafida  (falls back to %USERPROFILE%\AppData\Roaming)
     * Linux:   $XDG_DATA_HOME/grafida  (falls back to ~/.local/share/grafida)
     */
    public static function dataDir(): string
    {
        $dir = self::resolveBaseDataDir();

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** Absolute path to the application's SQLite database file. */
    public static function databaseFile(): string
    {
        return self::dataDir() . \DIRECTORY_SEPARATOR . 'grafida.sqlite';
    }

    /**
     * Absolute path to the per-user configuration directory.
     *
     * This differs from {@see dataDir()} only on Linux, where the XDG base-dir
     * spec separates *config* ($XDG_CONFIG_HOME) from *data* ($XDG_DATA_HOME).
     *
     * macOS:   ~/Library/Application Support/Grafida
     * Windows: %APPDATA%\Grafida  (falls back to %USERPROFILE%\AppData\Roaming)
     * Linux:   $XDG_CONFIG_HOME/grafida  (falls back to ~/.config/grafida)
     */
    public static function configDir(): string
    {
        $dir = self::resolveBaseConfigDir();

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** Absolute path to the cached update-information file. */
    public static function updatesFile(): string
    {
        return self::configDir() . \DIRECTORY_SEPARATOR . 'updates.json';
    }

    private static function resolveBaseDataDir(): string
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            return self::home() . '/Library/Application Support/' . self::APP_DIR_NAME;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $appDataEnv = getenv('APPDATA');
            $appData    = $appDataEnv !== false ? $appDataEnv : (self::home() . '\\AppData\\Roaming');

            return rtrim($appData, '\\/') . '\\' . self::APP_DIR_NAME;
        }

        // Linux and other *nix
        $xdgEnv = getenv('XDG_DATA_HOME');
        $xdg    = $xdgEnv !== false ? $xdgEnv : (self::home() . '/.local/share');

        return rtrim($xdg, '/') . '/' . strtolower(self::APP_DIR_NAME);
    }

    private static function resolveBaseConfigDir(): string
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            return self::home() . '/Library/Application Support/' . self::APP_DIR_NAME;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $appDataEnv = getenv('APPDATA');
            $appData    = $appDataEnv !== false ? $appDataEnv : (self::home() . '\\AppData\\Roaming');

            return rtrim($appData, '\\/') . '\\' . self::APP_DIR_NAME;
        }

        // Linux and other *nix
        $xdgEnv = getenv('XDG_CONFIG_HOME');
        $xdg    = $xdgEnv !== false ? $xdgEnv : (self::home() . '/.config');

        return rtrim($xdg, '/') . '/' . strtolower(self::APP_DIR_NAME);
    }

    private static function home(): string
    {
        $home = getenv('HOME');

        if ($home !== false) {
            return rtrim($home, '\\/');
        }

        // Windows fallback
        $profile = getenv('USERPROFILE');

        if ($profile !== false) {
            return rtrim($profile, '\\/');
        }

        return sys_get_temp_dir();
    }
}
