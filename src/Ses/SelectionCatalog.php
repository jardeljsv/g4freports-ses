<?php

namespace GlpiPlugin\G4freports\Ses;

use GlpiPlugin\G4freports\Database\ConnectionException;
use GlpiPlugin\G4freports\Database\MysqlClient;

/** Resolve selected IDs against the same database used for the monthly tickets. */
final class SelectionCatalog
{
    public static function resolve(MysqlClient $client, array $input, array &$warnings): array
    {
        $groupIds = array_values(array_unique(array_merge($input['n1_group_ids'], $input['n2_group_ids'], $input['n3_group_ids'], $input['group_ids'])));
        $catalog = [
            'groups' => self::required($client, 'groups', $groupIds, 'grupos'),
            'categories' => self::required($client, 'categories', $input['n1_categories'], 'categorias de chamados elegíveis N1'),
            'articles' => [],
            'articles_available' => true,
        ];
        $articleIds = $input['validated_kb_ids'];
        if (!$articleIds) {
            return $catalog;
        }
        try {
            $catalog['articles'] = self::lookup($client, 'articles', $articleIds);
        } catch (ConnectionException $e) {
            if (!self::isUnavailable($e)) {
                throw $e;
            }
            $catalog['articles_available'] = false;
            $warnings[] = 'O catálogo de artigos da base de conhecimento não está acessível nesta conexão. '
                . 'Não foi possível conferir os IDs de artigos informados; o INS11 por artigos validados ficará pendente.';
            return $catalog;
        }
        if ($input['kb_mode'] === 'validated_links') {
            self::assertPresent($articleIds, $catalog['articles'], 'artigos da base de conhecimento',
                ' Informe IDs de artigos; IDs de categorias da base de conhecimento são um cadastro diferente.');
        }
        return $catalog;
    }

    private static function required(MysqlClient $client, string $kind, array $ids, string $label): array
    {
        try {
            $rows = self::lookup($client, $kind, $ids);
        } catch (ConnectionException $e) {
            if (!self::isUnavailable($e)) {
                throw $e;
            }
            throw new \RuntimeException('Não foi possível conferir os IDs de ' . $label
                . ' no banco GLPI configurado para o relatório SES. Verifique o catálogo e a permissão de leitura antes de gerar a extração.', 0, $e);
        }
        self::assertPresent($ids, $rows, $label);
        return $rows;
    }

    private static function assertPresent(array $ids, array $rows, string $label, string $detail = ''): void
    {
        $missing = array_values(array_diff($ids, array_keys($rows)));
        if ($missing) {
            throw new \InvalidArgumentException('Seleção inválida: os seguintes IDs de ' . $label
                . ' não existem no banco GLPI configurado para este relatório: ' . implode(', ', $missing) . '.' . $detail);
        }
    }

    /** Fixed identifiers only; values are bound and selected IDs are read in bounded batches. */
    private static function lookup(MysqlClient $client, string $kind, array $ids): array
    {
        $queries = [
            'groups' => 'SELECT id, completename AS name, entities_id AS entity_id FROM glpi_groups',
            'categories' => 'SELECT id, completename AS name, entities_id AS entity_id FROM glpi_itilcategories',
            // Article entity columns differ between GLPI releases; ID/title are sufficient here.
            'articles' => 'SELECT id, name FROM glpi_knowbaseitems',
        ];
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $client->fetchAll($queries[$kind]
                . ' WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id', $chunk, 1000);
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $result[$id] = ['id' => $id, 'name' => html_entity_decode((string)$row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
                if (array_key_exists('entity_id', $row)) {
                    $result[$id]['entity_id'] = (int)$row['entity_id'];
                }
            }
        }
        return $result;
    }

    private static function isUnavailable(ConnectionException $error): bool
    {
        return in_array($error->getReason(), ['missing_table', 'missing_columns', 'permission_denied'], true);
    }
}
