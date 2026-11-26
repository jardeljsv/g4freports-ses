<?php

namespace GlpiPlugin\G4freports\Ses;

/** Bounded private files used by archive access metadata, never operational data. */
final class ArchiveFiles
{
    public static function directory(array $parts = [], bool $create = false): ?string
    {
        if (!defined('GLPI_PLUGIN_DOC_DIR') || !is_string(GLPI_PLUGIN_DOC_DIR) || GLPI_PLUGIN_DOC_DIR === ''
            || strpos(GLPI_PLUGIN_DOC_DIR, "\0") !== false || is_link(rtrim(GLPI_PLUGIN_DOC_DIR, '/\\'))) throw self::error();
        $path = realpath(GLPI_PLUGIN_DOC_DIR);
        if ($path === false || !is_dir($path)) throw self::error();
        foreach (array_merge(['g4freports', 'monthly_archive'], $parts) as $i => $part) {
            if (!is_string($part) || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $part)) throw self::error();
            $path .= '/' . $part;
            if (!self::ensureDirectory($path, $create, $i !== 0)) return null;
        }
        return $path;
    }

    private static function ensureDirectory(string $path, bool $create, bool $private): bool
    {
        clearstatcache(true, $path);
        if (is_link($path)) throw self::error();
        if (!file_exists($path)) {
            if (!$create) return false;
            if (!@mkdir($path, 0700) && !is_dir($path)) throw self::error();
            if (is_link($path) || !@chmod($path, 0700)) throw self::error();
        }
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($private && ($stat['mode'] & 0077))) throw self::error();
        return true;
    }

    public static function read(string $path, int $limit): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path)) throw self::error();
        if (!file_exists($path)) return null;
        $before = self::fileStat($path, $limit);
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw self::error();
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) throw self::error();
            $bytes = stream_get_contents($handle, $limit + 1);
            if (!is_string($bytes) || strlen($bytes) !== $opened['size'] || strlen($bytes) > $limit) throw self::error();
            return $bytes;
        } finally { fclose($handle); }
    }

    public static function replace(string $path, string $bytes): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) throw self::error();
        if (file_exists($path)) self::fileStat($path, PHP_INT_MAX);
        $temporary = dirname($path) . '/.tmp-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) throw self::error();
        try {
            if (!@chmod($temporary, 0600)) throw self::error();
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written <= 0) throw self::error();
                $offset += $written;
            }
            if (!fflush($handle)) throw self::error();
            if (function_exists('fsync') && !@fsync($handle)) throw self::error();
            fclose($handle); $handle = null;
            if (is_link($path) || !@rename($temporary, $path)) throw self::error();
        } finally { if (is_resource($handle)) fclose($handle); if (file_exists($temporary)) @unlink($temporary); }
    }

    /** @return resource */
    public static function lock(string $path, string $busyMessage)
    {
        clearstatcache(true, $path);
        if (is_link($path)) throw self::error();
        if (file_exists($path)) self::fileStat($path, 0);
        $handle = @fopen($path, 'x+b');
        if ($handle === false) $handle = @fopen($path, 'r+b');
        if ($handle === false) throw self::error();
        try {
            if (is_link($path) || !@chmod($path, 0600)) throw self::error();
            $stat = self::fileStat($path, 0); $opened = fstat($handle);
            if (!is_array($opened) || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']) throw self::error();
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new \RuntimeException($busyMessage, 409);
            // Keep the live temporary admission file recent for ordinary age-based cleanup.
            if (!@touch($path)) throw self::error();
            return $handle;
        } catch (\Throwable $error) { fclose($handle); throw $error; }
    }

    public static function unlock($handle): void
    {
        if (is_resource($handle)) { @flock($handle, LOCK_UN); fclose($handle); }
    }

    private static function fileStat(string $path, int $limit): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) || $stat['nlink'] !== 1
            || $stat['size'] < 0 || $stat['size'] > $limit) throw self::error();
        return $stat;
    }

    public static function error(): \RuntimeException
    {
        return new \RuntimeException('O armazenamento do arquivo compartilhado está indisponível ou não passou na verificação de integridade.', 503);
    }
}
