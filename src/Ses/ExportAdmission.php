<?php

namespace GlpiPlugin\G4freports\Ses;

/** One large monthly Office render per instance/temp volume, before JSON decoding. */
final class ExportAdmission
{
    private static $watchedHandle = null;
    private static ?string $reserve = null;
    private static bool $shutdownRegistered = false;
    public static function acquire()
    {
        // Normal monthly export already depends on this temporary volume. It must
        // not acquire a new dependency on the optional permanent archive store.
        $base = defined('GLPI_TMP_DIR') ? (string)GLPI_TMP_DIR
            : (defined('GLPI_ROOT') ? GLPI_ROOT . '/files/_tmp' : sys_get_temp_dir());
        if ($base === '' || strpos($base, "\0") !== false) throw self::unavailable();
        $base = realpath($base);
        if ($base === false || !is_dir($base)) throw self::unavailable();
        $identity = defined('GLPI_ROOT') ? (string)GLPI_ROOT : dirname(__DIR__, 3);
        $directory = rtrim($base, '/\\') . '/g4freports_ses_export_' . substr(hash('sha256', $identity), 0, 12);
        clearstatcache(true, $directory);
        if (is_link($directory)) throw self::unavailable();
        if (!file_exists($directory)) {
            if (!@mkdir($directory, 0700) && !is_dir($directory)) throw self::unavailable();
            if (is_link($directory) || !@chmod($directory, 0700)) throw self::unavailable();
        }
        $stat = @lstat($directory);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0077)) throw self::unavailable();
        try {
            $handle = ArchiveFiles::lock($directory . '/.monthly-office-export.lock',
                'Outra exportação mensal está em andamento. Aguarde sua conclusão e tente novamente.');
            self::watchResourceFailure($handle);
            return $handle;
        } catch (\RuntimeException $error) {
            if ($error->getCode() === 503) throw self::unavailable();
            throw $error;
        }
    }

    public static function release($handle): void
    {
        if (is_resource($handle) && $handle === self::$watchedHandle) {
            self::$watchedHandle = null; self::$reserve = null;
        }
        ArchiveFiles::unlock($handle);
    }

    /** A fatal allocation/time limit cannot be caught by the controller's Throwable handler. */
    private static function watchResourceFailure($handle): void
    {
        if (PHP_SAPI === 'cli') return;
        self::$watchedHandle = $handle;
        if (!self::$shutdownRegistered) {
            register_shutdown_function(static function (): void {
                $error = error_get_last();
                if (!is_resource(self::$watchedHandle) || !is_array($error) || ($error['type'] ?? null) !== E_ERROR
                    || !preg_match('/\A(?:Allowed memory size of .* exhausted|Maximum execution time of .* exceeded)/', (string)($error['message'] ?? ''))) return;
                self::$reserve = null; // Leave space for the small, non-sensitive error response.
                ArchiveFiles::unlock(self::$watchedHandle); self::$watchedHandle = null;
                if (headers_sent()) return;
                while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
                header_remove('Content-Length'); header_remove('Content-Disposition');
                http_response_code(503);
                header('HTTP/1.1 503 Service Unavailable', true, 503);
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
                echo '{"ok":false,"error":"A exportação mensal excedeu os recursos disponíveis no servidor. Os dados salvos não foram alterados. Solicite a revisão dos limites de memória ou tempo ao administrador."}';
            });
            self::$shutdownRegistered = true;
        }
        self::$reserve = str_repeat(' ', 131072);
    }

    private static function unavailable(): \RuntimeException
    {
        return new \RuntimeException('A área temporária da exportação mensal está indisponível ou sem as permissões necessárias.', 503);
    }
}
