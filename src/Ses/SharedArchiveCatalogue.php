<?php

namespace GlpiPlugin\G4freports\Ses;

/** Shared metadata only: one bounded, atomically published index per year. */
final class SharedArchiveCatalogue
{
    public const MAX_BYTES = 4194304;
    public const MAX_RECORDS = 4096;
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function reference(int $owner, string $month, string $id): string
    {
        if ($owner <= 0) throw new \InvalidArgumentException('Responsável pelo arquivo inválido.');
        AnnualSelection::month($month); AnnualSelection::id($id);
        return hash('sha256', 'g4freports-shared-v1' . "\0" . $owner . "\0" . $month . "\0" . $id);
    }

    public static function listYear(int $year): array
    {
        AnnualSelection::year($year);
        $directory = ArchiveFiles::directory(['shared_catalogue'], false);
        return $directory === null ? [] : self::read($directory . '/' . $year . '.json', $year);
    }

    public static function resolve(string $reference, string $month, string $id): array
    {
        AnnualSelection::id($reference); AnnualSelection::month($month); AnnualSelection::id($id);
        $records = self::listYear((int)substr($month, 0, 4));
        $record = $records[$reference] ?? null;
        if (!is_array($record) || $record['month'] !== $month || $record['id'] !== $id) throw ArchiveAccess::notFound();
        return $record;
    }

    public static function publish(int $owner, string $id, string $month): array
    {
        // Read the immutable manifest before entering the short catalogue lock.
        $metadata = MonthlyArchiveStore::loadMetadata($owner, $id, $month);
        $reference = self::reference($owner, $month, $id);
        $year = (int)substr($month, 0, 4);
        $directory = ArchiveFiles::directory(['shared_catalogue'], true);
        $lock = ArchiveFiles::lock($directory . '/.' . $year . '.lock', 'Outra publicação deste ano está em andamento. Tente novamente.');
        try {
            $path = $directory . '/' . $year . '.json';
            $records = self::read($path, $year);
            if (isset($records[$reference])) return $records[$reference];
            if (count($records) >= self::MAX_RECORDS) throw new \RuntimeException('O catálogo compartilhado deste ano atingiu seu limite. O arquivo mensal permanece preservado.', 409);
            $record = ['archive_ref' => $reference, 'owner' => $owner, 'month' => $month, 'id' => $id,
                'shared_at' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM), 'metadata' => $metadata];
            $records[$reference] = $record;
            $data = ['schema' => 1, 'year' => $year, 'records' => $records];
            $json = json_encode(['data' => $data, 'sha256' => hash('sha256', json_encode($data, self::FLAGS))], self::FLAGS);
            if (strlen($json) > self::MAX_BYTES) throw new \RuntimeException('O catálogo compartilhado deste ano atingiu seu limite de tamanho. O arquivo mensal permanece preservado.', 409);
            ArchiveFiles::replace($path, $json);
            return $record;
        } finally { ArchiveFiles::unlock($lock); }
    }

    private static function read(string $path, int $year): array
    {
        $bytes = ArchiveFiles::read($path, self::MAX_BYTES);
        if ($bytes === null) return [];
        try { $envelope = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw ArchiveFiles::error(); }
        $data = $envelope['data'] ?? null;
        if (!is_array($envelope) || !is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['year'] ?? null) !== $year
            || !is_array($data['records'] ?? null) || count($data['records']) > self::MAX_RECORDS
            || !is_string($envelope['sha256'] ?? null)
            || !hash_equals(hash('sha256', json_encode($data, self::FLAGS)), $envelope['sha256'])) throw ArchiveFiles::error();
        foreach ($data['records'] as $key => $record) {
            if (!is_array($record) || !is_int($record['owner'] ?? null) || $record['owner'] <= 0
                || !is_string($record['month'] ?? null) || !is_string($record['id'] ?? null)
                || !is_string($record['shared_at'] ?? null) || !is_array($record['metadata'] ?? null)) throw ArchiveFiles::error();
            try { $reference = self::reference($record['owner'], $record['month'], $record['id']); }
            catch (\InvalidArgumentException $error) { throw ArchiveFiles::error(); }
            if ($key !== $reference || ($record['archive_ref'] ?? null) !== $reference || (int)substr($record['month'], 0, 4) !== $year
                || ($record['metadata']['id'] ?? null) !== $record['id'] || ($record['metadata']['month'] ?? null) !== $record['month']) throw ArchiveFiles::error();
        }
        return $data['records'];
    }
}
