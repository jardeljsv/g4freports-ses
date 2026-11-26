<?php

namespace GlpiPlugin\G4freports\Ses;

/** Short-lived request chunks; keeps large narratives below proxy URL limits. */
final class InputStore
{
    private const TTL = 600;
    private const LIMIT = 128000;

    private static function directory(): string
    {
        $base = defined('GLPI_TMP_DIR') ? (string)GLPI_TMP_DIR
            : (defined('GLPI_ROOT') ? GLPI_ROOT . '/files/_tmp' : sys_get_temp_dir());
        $directory = rtrim($base, '/\\') . '/g4freports_ses_inputs_' . substr(hash('sha256', dirname(__DIR__, 3)), 0, 12);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar o envio dos parâmetros.');
        }
        return $directory;
    }

    private static function path(string $token): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) throw new \InvalidArgumentException('Identificador de envio inválido.');
        return self::directory() . '/' . $token . '.json';
    }

    public static function start(int $owner, int $size, int $count): string
    {
        if ($owner <= 0 || $size < 2 || $size > self::LIMIT || $count < 1 || $count > 128 || $size > $count * 1024) {
            throw new \InvalidArgumentException('Os parâmetros do relatório excedem o tamanho permitido.');
        }
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            if (time() - (int)filemtime($file) > self::TTL) @unlink($file);
        }
        $token = bin2hex(random_bytes(32));
        self::write($token, ['owner' => $owner, 'created' => time(), 'size' => $size, 'count' => $count, 'chunks' => [], 'ready' => false]);
        return $token;
    }

    private static function read(string $token, int $owner): array
    {
        $path = self::path($token);
        $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
        if (!is_array($data) || (int)($data['owner'] ?? 0) !== $owner || $owner <= 0 || time() - (int)($data['created'] ?? 0) > self::TTL) {
            throw new \RuntimeException('O envio dos parâmetros expirou ou não pertence a este usuário. Tente novamente.');
        }
        return $data;
    }

    public static function chunk(string $token, int $owner, int $index, string $base64): void
    {
        $data = self::read($token, $owner);
        $bytes = base64_decode($base64, true);
        if ($data['ready'] || $index < 0 || $index >= $data['count'] || $bytes === false || strlen($bytes) < 1 || strlen($bytes) > 1024 || strlen($base64) > 1400) {
            throw new \InvalidArgumentException('Fragmento de parâmetros inválido.');
        }
        $data['chunks'][(string)$index] = base64_encode($bytes);
        self::write($token, $data);
    }

    private static function decode(array $data): array
    {
        if (count($data['chunks']) !== $data['count']) throw new \RuntimeException('O envio dos parâmetros está incompleto.');
        $text = '';
        for ($index = 0; $index < $data['count']; $index++) {
            if (!isset($data['chunks'][$index])) throw new \RuntimeException('O envio dos parâmetros está incompleto.');
            $text .= base64_decode($data['chunks'][$index], true);
        }
        if (strlen($text) !== $data['size'] || strlen($text) > self::LIMIT) throw new \RuntimeException('O tamanho dos parâmetros recebidos é inválido.');
        $input = json_decode($text, true);
        if (!is_array($input)) throw new \InvalidArgumentException('Parâmetros de relatório inválidos.');
        return $input;
    }

    public static function finish(string $token, int $owner): void
    {
        $data = self::read($token, $owner);
        self::decode($data);
        $data['ready'] = true;
        self::write($token, $data);
    }

    public static function take(string $token, int $owner): array
    {
        $data = self::read($token, $owner);
        if (!$data['ready']) throw new \RuntimeException('Conclua o envio dos parâmetros antes de gerar o relatório.');
        $input = self::decode($data);
        @unlink(self::path($token));
        return $input;
    }

    private static function write(string $token, array $data): void
    {
        $path = self::path($token);
        if (@file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('Não foi possível salvar os parâmetros do relatório.');
        @chmod($path, 0600);
    }
}
