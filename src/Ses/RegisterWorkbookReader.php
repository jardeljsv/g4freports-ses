<?php

namespace GlpiPlugin\G4freports\Ses;

/** Bounded reader for untrusted register workbooks; never evaluates formulas. */
final class RegisterWorkbookReader
{
    public const MAX_BYTES = 2097152;
    private const MAX_PARTS = 100;
    private const MAX_PART_BYTES = 4194304;
    private const MAX_INFLATED_BYTES = 12582912;
    private const MAX_ROWS = 15000;
    private const MAX_CELLS = 60000;
    private const MAX_TEXT_BYTES = 10000;
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @return array{sheets:array,types:array,date1904:bool} */
    public static function read(string $binary): array
    {
        $parts = self::unzip($binary);
        // Validate even unreferenced XML: an ignored sheet must not hide formulas,
        // external relationships, macros or entity declarations in an import file.
        foreach ($parts as $name => $content) {
            if (preg_match('~(?:^|/)(?:vbaProject|activeX|embeddings|externalLinks)(?:[./]|$)|\.bin$~i', $name)) {
                self::fail('Macros, objetos incorporados e vínculos externos não são aceitos.');
            }
            if (preg_match('/\.(?:xml|rels)$/i', $name)) {
                $dom = self::xml($content);
                $xp = new \DOMXPath($dom);
                if ($xp->query('//*[local-name()="f" or local-name()="ddeLink" or local-name()="oleLink"]')->length) {
                    self::fail('A planilha contém fórmulas ou vínculos. Importe somente valores.');
                }
                if (str_ends_with($name, '.rels')) self::relationships($dom, self::relationshipSource($name), $parts);
                if ($name === '[Content_Types].xml') {
                    foreach ($dom->getElementsByTagName('*') as $element) {
                        $type = strtolower($element->getAttribute('ContentType'));
                        if (str_contains($type, 'macroenabled') || str_contains($type, 'vba') || str_contains($type, 'activex')) {
                            self::fail('Macros não são aceitas na importação.');
                        }
                    }
                }
                unset($xp, $dom);
            }
        }
        if (!isset($parts['[Content_Types].xml'], $parts['_rels/.rels'])) self::fail('O arquivo não é uma planilha XLSX.');
        $rootRels = self::relationships(self::xml($parts['_rels/.rels']), '', $parts);
        $workbookPath = self::relationshipByType($rootRels, self::DOC_REL_NS . '/officeDocument', true);
        $workbook = self::xml($parts[$workbookPath]);
        if ($workbook->documentElement->namespaceURI !== self::NS || $workbook->documentElement->localName !== 'workbook') {
            self::fail('Formato de planilha não suportado. Salve como Pasta de Trabalho do Excel (.xlsx).');
        }
        $relsPath = self::relationshipPath($workbookPath);
        if (!isset($parts[$relsPath])) self::fail('Os vínculos internos da planilha estão incompletos.');
        $rels = self::relationships(self::xml($parts[$relsPath]), $workbookPath, $parts);
        $shared = [];
        $sharedPath = self::relationshipByType($rels, self::DOC_REL_NS . '/sharedStrings', false);
        if ($sharedPath !== null) {
            $dom = self::xml($parts[$sharedPath]);
            $xp = new \DOMXPath($dom); $xp->registerNamespace('s', self::NS);
            if ($dom->documentElement->namespaceURI !== self::NS || $dom->documentElement->localName !== 'sst') self::fail('Textos compartilhados inválidos.');
            foreach ($xp->query('/s:sst/s:si') as $item) {
                if (count($shared) >= self::MAX_CELLS) self::fail('A planilha contém textos demais.');
                $shared[] = self::richText($item, $xp);
            }
            unset($xp, $dom);
        }
        $xp = new \DOMXPath($workbook); $xp->registerNamespace('s', self::NS);
        $date1904 = $xp->evaluate('string(/s:workbook/s:workbookPr/@date1904)');
        if (!in_array($date1904, ['', '0', '1', 'false', 'true'], true)) self::fail('Sistema de datas inválido.');
        $result = ['sheets' => [], 'types' => [], 'date1904' => in_array($date1904, ['1', 'true'], true)];
        $usedPaths = []; $cells = 0;
        foreach ($xp->query('/s:workbook/s:sheets/s:sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $id = $sheet->getAttributeNS(self::DOC_REL_NS, 'id');
            if ($name === '' || strlen($name) > 200 || isset($result['sheets'][$name]) || !isset($rels[$id])
                || $rels[$id]['type'] !== self::DOC_REL_NS . '/worksheet') self::fail('Identificação de aba inválida ou repetida.');
            $path = $rels[$id]['target'];
            if (isset($usedPaths[$path])) self::fail('Abas apontam para o mesmo conteúdo.');
            $usedPaths[$path] = true;
            [$rows, $types] = self::sheet($parts[$path], $shared, $cells);
            $result['sheets'][$name] = $rows;
            $result['types'][$name] = $types;
        }
        if (!$result['sheets']) self::fail('A planilha não contém abas.');
        return $result;
    }

    private static function fail(string $message): void
    {
        throw new \InvalidArgumentException($message);
    }

    /** ZIP is read in memory, with sizes checked before bounded inflation. */
    private static function unzip(string $binary): array
    {
        $length = strlen($binary);
        if ($length < 22 || $length > self::MAX_BYTES || substr($binary, 0, 4) !== "PK\x03\x04") self::fail('Envie uma planilha XLSX de até 2 MB.');
        $end = false;
        // A ZIP comment can contain the end signature. Only a record whose
        // declared comment reaches the end of the file is an actual candidate.
        for ($i = $length - 22, $minimum = max(0, $length - 65557); $i >= $minimum; $i--) {
            if (substr($binary, $i, 4) === "PK\x05\x06") {
                $comment = unpack('vlength', substr($binary, $i + 20, 2))['length'];
                if ($i + 22 + $comment === $length) { $end = $i; break; }
            }
        }
        if ($end === false) self::fail('Arquivo XLSX incompleto.');
        $e = unpack('Vsignature/vdisk/vstart/ventriesDisk/ventries/Vsize/Voffset/vcomment', substr($binary, $end, 22));
        if ($e['disk'] !== 0 || $e['start'] !== 0 || $e['entriesDisk'] !== $e['entries'] || $e['entries'] < 1
            || $e['entries'] > self::MAX_PARTS || $e['size'] === 0xffffffff || $e['offset'] === 0xffffffff
            || $e['offset'] + $e['size'] !== $end) self::fail('Estrutura ZIP não suportada ou extensa demais.');
        $offset = $e['offset']; $files = []; $names = []; $ranges = []; $total = 0;
        for ($i = 0; $i < $e['entries']; $i++) {
            if ($offset + 46 > $end) self::fail('Diretório ZIP truncado.');
            $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($binary, $offset, 46));
            $next = $offset + 46 + $h['name'] + $h['extra'] + $h['comment'];
            if ($h['sig'] !== 0x02014b50 || $next > $end || $h['disk'] !== 0 || $h['need'] > 20
                || ($h['flags'] & ~0x080e) !== 0 || !in_array($h['method'], [0, 8], true)
                || $h['size'] > self::MAX_PART_BYTES || $h['compressed'] > self::MAX_BYTES
                || $h['offset'] + 30 > $e['offset']) self::fail('Componente ZIP inválido ou não suportado.');
            $name = substr($binary, $offset + 46, $h['name']);
            if ($name === '' || strlen($name) > 300 || preg_match('~[\\\\\x00-\x1f\x7f:%?#]|^/|(?:^|/)\.{1,2}(?:/|$)|//~', $name)
                || isset($names[$name])) self::fail('Nome de componente inválido ou repetido.');
            $names[$name] = true;
            self::extra(substr($binary, $offset + 46 + $h['name'], $h['extra']));
            $total += $h['size'];
            if ($total > self::MAX_INFLATED_BYTES) self::fail('A planilha descompactada excede o limite de 12 MB.');
            $local = unpack('Vsig/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr($binary, $h['offset'], 30));
            $dataOffset = $h['offset'] + 30 + $local['name'] + $local['extra'];
            if ($local['sig'] !== 0x04034b50 || $local['version'] !== $h['need'] || $local['flags'] !== $h['flags']
                || $local['method'] !== $h['method'] || $dataOffset + $h['compressed'] > $e['offset']
                || substr($binary, $h['offset'] + 30, $local['name']) !== $name) self::fail('Cabeçalho ZIP inconsistente.');
            self::extra(substr($binary, $h['offset'] + 30 + $local['name'], $local['extra']));
            $rangeEnd = $dataOffset + $h['compressed'];
            foreach (['crc', 'compressed', 'size'] as $field) {
                if ($local[$field] !== $h[$field] && (!(($h['flags'] & 8) !== 0) || $local[$field] !== 0)) self::fail('Tamanho ou verificação ZIP inconsistente.');
            }
            if (($h['flags'] & 8) !== 0) {
                $descriptorOffset = $rangeEnd;
                if (substr($binary, $descriptorOffset, 4) === "PK\x07\x08") $descriptorOffset += 4;
                if ($descriptorOffset + 12 > $e['offset']) self::fail('Descritor ZIP truncado.');
                $descriptor = unpack('Vcrc/Vcompressed/Vsize', substr($binary, $descriptorOffset, 12));
                if ($descriptor !== ['crc' => $h['crc'], 'compressed' => $h['compressed'], 'size' => $h['size']]) self::fail('Descritor ZIP inconsistente.');
                $rangeEnd = $descriptorOffset + 12;
            }
            foreach ($ranges as [$start, $finish]) {
                if ($h['offset'] < $finish && $rangeEnd > $start) self::fail('Componentes ZIP sobrepostos.');
            }
            $ranges[] = [$h['offset'], $rangeEnd];
            $compressed = substr($binary, $dataOffset, $h['compressed']);
            $content = $h['method'] === 8 ? @gzinflate($compressed, $h['size'] + 1) : $compressed;
            if (!is_string($content) || strlen($content) !== $h['size'] || crc32($content) !== $h['crc']) self::fail('Conteúdo XLSX corrompido.');
            if (str_ends_with($name, '/')) {
                if ($content !== '') self::fail('Diretório ZIP inválido.');
            } else $files[$name] = $content;
            $offset = $next;
        }
        if ($offset !== $end) self::fail('Diretório ZIP inconsistente.');
        return $files;
    }

    private static function extra(string $extra): void
    {
        for ($offset = 0, $length = strlen($extra); $offset < $length;) {
            if ($offset + 4 > $length) self::fail('Metadados ZIP inválidos.');
            $header = unpack('vid/vsize', substr($extra, $offset, 4));
            if ($header['id'] === 1 || $offset + 4 + $header['size'] > $length) self::fail('ZIP64 ou metadados ZIP inválidos.');
            $offset += 4 + $header['size'];
        }
    }

    private static function xml(string $text): \DOMDocument
    {
        if (str_contains($text, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $text)) self::fail('Declarações de entidades XML não são aceitas.');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->resolveExternals = false; $dom->substituteEntities = false;
            if (!$dom->loadXML($text, LIBXML_NONET | LIBXML_COMPACT) || $dom->doctype !== null) self::fail('XML da planilha inválido.');
            return $dom;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private static function relationshipSource(string $path): string
    {
        if ($path === '_rels/.rels') return '';
        if (!preg_match('~\A(?:(.*)/)?_rels/([^/]+)\.rels\z~D', $path, $m)) self::fail('Local de vínculos internos inválido.');
        return ($m[1] !== '' ? $m[1] . '/' : '') . $m[2];
    }

    private static function relationshipPath(string $source): string
    {
        $dir = dirname($source);
        return ($dir === '.' ? '' : $dir . '/') . '_rels/' . basename($source) . '.rels';
    }

    private static function relationships(\DOMDocument $dom, string $source, array $parts): array
    {
        if ($dom->documentElement->namespaceURI !== self::REL_NS || $dom->documentElement->localName !== 'Relationships') self::fail('Vínculos internos inválidos.');
        $result = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if (!$node instanceof \DOMElement) continue;
            if ($node->namespaceURI !== self::REL_NS || $node->localName !== 'Relationship') self::fail('Vínculo interno desconhecido.');
            $id = $node->getAttribute('Id'); $type = $node->getAttribute('Type'); $mode = $node->getAttribute('TargetMode');
            if (!in_array($mode, ['', 'Internal'], true)) self::fail('Vínculos externos não são aceitos na importação.');
            if ($id === '' || isset($result[$id]) || $type === '') self::fail('Vínculo interno repetido ou incompleto.');
            $target = self::target($source, $node->getAttribute('Target'));
            if (!isset($parts[$target])) self::fail('A planilha contém um vínculo interno sem destino.');
            $result[$id] = ['type' => $type, 'target' => $target];
        }
        return $result;
    }

    private static function target(string $source, string $target): string
    {
        if ($target === '' || preg_match('~[\\\\\x00-\x20\x7f:%?#]~', $target)) self::fail('Destino de vínculo inválido.');
        $dir = $source === '' ? '' : dirname($source);
        $segments = str_starts_with($target, '/') || $dir === '.' || $dir === '' ? [] : explode('/', $dir);
        foreach (explode('/', ltrim($target, '/')) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                if (!$segments) self::fail('Destino fora da planilha.');
                array_pop($segments);
            } else $segments[] = $segment;
        }
        if (!$segments) self::fail('Destino de vínculo vazio.');
        return implode('/', $segments);
    }

    private static function relationshipByType(array $rels, string $type, bool $required): ?string
    {
        $found = null;
        foreach ($rels as $rel) if ($rel['type'] === $type) {
            if ($found !== null) self::fail('Vínculo de planilha repetido.');
            $found = $rel['target'];
        }
        if ($required && $found === null) self::fail('Conteúdo principal da planilha ausente.');
        return $found;
    }

    private static function richText(\DOMNode $node, \DOMXPath $xp): string
    {
        $value = '';
        foreach ($xp->query('s:t | s:r/s:t', $node) as $text) $value .= $text->textContent;
        if (strlen($value) > self::MAX_TEXT_BYTES) self::fail('Uma célula contém texto extenso demais.');
        return $value;
    }

    private static function sheet(string $text, array $shared, int &$cellCount): array
    {
        $dom = self::xml($text); $xp = new \DOMXPath($dom); $xp->registerNamespace('s', self::NS);
        if ($dom->documentElement->namespaceURI !== self::NS || $dom->documentElement->localName !== 'worksheet') self::fail('Conteúdo de aba inválido.');
        if ($xp->query('//s:f')->length) self::fail('A planilha contém fórmulas. Importe somente valores.');
        if ($xp->query('/s:worksheet/s:sheetData')->length !== 1) self::fail('Dados da aba ausentes ou repetidos.');
        $rows = []; $types = []; $seenRows = [];
        foreach ($xp->query('/s:worksheet/s:sheetData/s:row') as $row) {
            $r = $row->getAttribute('r');
            if (!preg_match('/\A[1-9][0-9]{0,4}\z/D', $r) || (int)$r > self::MAX_ROWS || isset($seenRows[$r])) self::fail('Linha inválida, repetida ou acima do limite de 15.000.');
            $r = (int)$r; $seenRows[$r] = true; $seenCells = [];
            foreach ($xp->query('s:c', $row) as $cell) {
                if (++$cellCount > self::MAX_CELLS) self::fail('A planilha excede o limite de 60.000 células.');
                $ref = $cell->getAttribute('r');
                if (!preg_match('/\A([A-Z]{1,3})([1-9][0-9]{0,4})\z/D', $ref, $m) || (int)$m[2] !== $r || isset($seenCells[$ref])) self::fail('Coordenada de célula inválida ou repetida.');
                $columnNumber = 0;
                foreach (str_split($m[1]) as $letter) $columnNumber = $columnNumber * 26 + ord($letter) - 64;
                if ($columnNumber > 16384) self::fail('Coluna de planilha inválida.');
                $seenCells[$ref] = true;
                $type = $cell->getAttribute('t') ?: 'n';
                if (!in_array($type, ['n', 's', 'inlineStr', 'b', 'd'], true)) self::fail('A célula ' . $ref . ' contém erro ou tipo não aceito. Use valores simples.');
                $values = $xp->query('s:v', $cell); $inline = $xp->query('s:is', $cell);
                if ($values->length > 1 || $inline->length > 1 || ($values->length && $inline->length)) self::fail('Célula com conteúdos incompatíveis.');
                if ($type === 'inlineStr') {
                    if ($values->length) self::fail('Texto de célula inconsistente.');
                    $value = $inline->length ? self::richText($inline->item(0), $xp) : '';
                } else {
                    if ($inline->length) self::fail('Tipo de célula inconsistente.');
                    $value = $values->length ? $values->item(0)->textContent : '';
                    if ($type === 's') {
                        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) || strlen($value) > 6 || !array_key_exists((int)$value, $shared)) self::fail('Índice de texto compartilhado inválido.');
                        $value = $shared[(int)$value];
                    } elseif ($type === 'n' && $value !== '' && (!preg_match('/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[Ee][+-]?[0-9]+)?\z/D', $value) || !is_finite((float)$value))) {
                        self::fail('Valor numérico inválido na célula ' . $ref . '.');
                    } elseif ($type === 'b' && !in_array($value, ['0', '1'], true)) self::fail('Valor lógico inválido.');
                }
                if (strlen($value) > self::MAX_TEXT_BYTES) self::fail('Uma célula contém texto extenso demais.');
                if ($value !== '') { $rows[$r][$m[1]] = $value; $types[$r][$m[1]] = $type; }
            }
        }
        ksort($rows); ksort($types);
        return [$rows, $types];
    }
}
