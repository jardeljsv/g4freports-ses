<?php

namespace GlpiPlugin\G4freports\Ses;

use GlpiPlugin\G4freports\Config;
use GlpiPlugin\G4freports\Security;

/** The authenticated requester and the immutable storage owner are separate identities. */
final class ArchiveAccess
{
    public static function status(?array $configuration = null): array
    {
        $configuration = $configuration ?? Config::merged();
        try { $groups = ArchiveSharingSettings::groups($configuration['ses_archive_shared_groups'] ?? ''); }
        catch (\InvalidArgumentException $error) { return ['enabled' => false, 'allowed' => false]; }
        $allowed = $groups && Security::currentUserId() > 0
            && (Security::canConfigure() || array_intersect($groups, self::groupIds(Security::currentUserId())));
        return ['enabled' => count($groups) > 0, 'allowed' => (bool)$allowed];
    }

    public static function listYear(int $requester, int $year, ?array $configuration = null): array
    {
        return self::listing($requester, $year, $configuration)['revisions'];
    }

    public static function listing(int $requester, int $year, ?array $configuration = null): array
    {
        self::requester($requester);
        $status = self::status($configuration);
        $own = MonthlyArchiveStore::listYear($requester, $year);
        $result = [];
        foreach ($own as $metadata) {
            $key = SharedArchiveCatalogue::reference($requester, $metadata['month'], $metadata['id']);
            $result[$key] = self::decorate($metadata, $requester, false, null, $status['allowed']);
        }
        // Private history must not depend on an unused shared index or its configuration.
        if (!$status['allowed']) {
            // Do not claim an older publication became private when this user no
            // longer has permission to consult the shared catalogue.
            foreach ($result as &$metadata) $metadata['visibility'] = 'personal';
            unset($metadata);
            return ['revisions' => array_values($result), 'sharing' => $status];
        }
        // No scan across owner directories. The index contains explicitly published metadata only.
        foreach (SharedArchiveCatalogue::listYear($year) as $reference => $record) {
            // Verified metadata was copied at publication. Data integrity is checked on access.
            $metadata = $record['metadata'];
            $result[$reference] = self::decorate($metadata, $record['owner'], true, $reference, false);
        }
        $result = array_values($result);
        usort($result, static fn(array $a, array $b): int => strcmp($a['month'], $b['month'])
            ?: strcmp($b['archived_at'], $a['archived_at']) ?: strcmp($a['archive_ref'] ?? $a['id'], $b['archive_ref'] ?? $b['id']));
        return ['revisions' => $result, 'sharing' => $status];
    }

    public static function publish(int $requester, string $id, string $month, ?array $configuration = null): array
    {
        self::requester($requester);
        if (!self::status($configuration)['allowed']) throw new \RuntimeException('O compartilhamento não está autorizado para este usuário.', 403);
        $record = SharedArchiveCatalogue::publish($requester, $id, $month);
        return self::decorate($record['metadata'], $record['owner'], true, $record['archive_ref'], false);
    }

    /** Resolve an authorized reference without trusting a browser-supplied owner or path. */
    public static function resolve(int $requester, string $id, string $month, ?string $reference = null, ?array $configuration = null): array
    {
        $selection = ['id' => $id, 'month' => $month];
        if ($reference !== null) $selection['archive_ref'] = $reference;
        return self::resolveMany($requester, [$selection], $configuration)[0];
    }

    /** One membership check and one bounded index read per selected year; no persistent ACL cache. */
    public static function resolveMany(int $requester, array $selections, ?array $configuration = null): array
    {
        self::requester($requester);
        if (count($selections) > 24) throw new \InvalidArgumentException('Selecione no máximo 24 revisões.');
        $years = []; $hasShared = false;
        foreach (array_values($selections) as $index => $selection) {
            $id = AnnualSelection::id($selection['id'] ?? null);
            $month = AnnualSelection::month($selection['month'] ?? null);
            $reference = self::reference($selection['archive_ref'] ?? null);
            $hasShared = $hasShared || $reference !== null;
            $years[(int)substr($month, 0, 4)][] = ['index' => $index, 'id' => $id, 'month' => $month, 'archive_ref' => $reference];
        }
        $sharedAllowed = $hasShared && self::status($configuration)['allowed'];
        $resolved = [];
        foreach ($years as $year => $items) {
            $catalogue = null;
            foreach ($items as $selection) {
                $owner = $requester; $reference = $selection['archive_ref'];
                if ($reference !== null && $sharedAllowed) {
                    if ($catalogue === null) $catalogue = SharedArchiveCatalogue::listYear($year);
                    $record = $catalogue[$reference] ?? null;
                    if (!is_array($record) || $record['month'] !== $selection['month'] || $record['id'] !== $selection['id']) throw self::notFound();
                    $owner = $record['owner'];
                } elseif ($reference !== null
                    && !hash_equals(SharedArchiveCatalogue::reference($requester, $selection['month'], $selection['id']), $reference)) {
                    // Revoked readers cannot probe the catalogue. The original owner's
                    // saved link can still use the private archive without consulting it.
                    throw self::notFound();
                }
                MonthlyArchiveStore::loadMetadata($owner, $selection['id'], $selection['month']);
                $resolved[$selection['index']] = ['owner' => $owner, 'id' => $selection['id'], 'month' => $selection['month']];
                if ($reference !== null) $resolved[$selection['index']]['archive_ref'] = $reference;
            }
            unset($catalogue); // Do not retain every year's potentially large index.
        }
        ksort($resolved, SORT_NUMERIC);
        return array_values($resolved);
    }

    public static function reference($value): ?string
    {
        return $value === null ? null : AnnualSelection::id($value);
    }

    public static function notFound(): \RuntimeException
    {
        return new \RuntimeException('Relatório arquivado indisponível para este usuário e competência.', 404);
    }

    private static function requester(int $requester): void
    {
        if ($requester <= 0 || $requester !== Security::currentUserId()) throw self::notFound();
    }

    /** Authoritative host-GLPI membership; session array keys are never group identities. */
    private static function groupIds(int $requester): array
    {
        global $DB;
        if ($requester <= 0 || !isset($DB) || !is_object($DB) || !method_exists($DB, 'request')) return [];
        try {
            $rows = $DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $requester]]);
            $groups = [];
            foreach ($rows as $row) {
                $value = is_array($row) ? ($row['groups_id'] ?? null) : null;
                if ((is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]*\z/D', (string)$value)
                    && (int)$value > 0 && (int)$value <= 2147483647) $groups[(int)$value] = (int)$value;
            }
            return array_values($groups);
        } catch (\Throwable $error) { return []; }
    }

    private static function decorate(array $metadata, int $creator, bool $shared, ?string $reference, bool $canShare): array
    {
        $metadata['creator_id'] = $creator;
        $metadata['visibility'] = $shared ? 'shared' : 'private';
        $metadata['can_share'] = $canShare && !$shared;
        if ($reference !== null) $metadata['archive_ref'] = $reference;
        return $metadata;
    }
}
