<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** Small OOXML package helper; no ZipArchive or Composer dependency. */
final class OfficePackage
{
    public static function xml($text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$text);
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function read(string $binary): array
    {
        $end = strrpos($binary, "PK\x05\x06");
        if ($end === false || strlen($binary) < $end + 22) {
            throw new \RuntimeException('O modelo de documento não é um arquivo OOXML válido.');
        }
        $e = unpack('Vsignature/vdisk/vstart/ventriesDisk/ventries/Vsize/Voffset/vcomment', substr($binary, $end, 22));
        $offset = $e['offset'];
        $files = [];
        for ($i = 0; $i < $e['entries']; $i++) {
            $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($binary, $offset, 46));
            if ($h['sig'] !== 0x02014b50) {
                throw new \RuntimeException('O modelo OOXML está incompleto.');
            }
            $name = substr($binary, $offset + 46, $h['name']);
            $local = unpack('Vsig/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr($binary, $h['offset'], 30));
            $data = substr($binary, $h['offset'] + 30 + $local['name'] + $local['extra'], $h['compressed']);
            if (substr($name, -1) !== '/') {
                if ($h['method'] === 8) {
                    $data = @gzinflate($data);
                } elseif ($h['method'] !== 0) {
                    $data = false;
                }
                if (!is_string($data) || strlen($data) !== $h['size']) {
                    throw new \RuntimeException('Não foi possível ler o modelo de documento.');
                }
                $files[$name] = $data;
            }
            $offset += 46 + $h['name'] + $h['extra'] + $h['comment'];
        }
        return $files;
    }

    /** @param array<string,string|\Closure|array{deflated:string,size:int,crc:int}> $files */
    public static function write(array $files): string
    {
        $data = '';
        $central = '';
        foreach ($files as $name => $content) {
            if ($content instanceof \Closure) {
                $content = $content();
            }
            if (is_array($content) && isset($content['deflated'], $content['size'], $content['crc'])) {
                // Large worksheets are already compressed incrementally. Their
                // verified CRC and original length let the ZIP writer avoid
                // inflating the complete worksheet again in PHP memory.
                $packed = $content['deflated'];
                $size = $content['size'];
                $crc = $content['crc'];
                if (!is_string($packed) || !is_int($size) || $size < 0 || !is_int($crc)) {
                    throw new \RuntimeException('Componente compactado inválido no relatório.');
                }
            } else {
                $content = (string)$content;
                $size = strlen($content);
                $packed = gzdeflate($content, 6);
                if (!is_string($packed)) {
                    throw new \RuntimeException('Não foi possível compactar o relatório.');
                }
                $crc = crc32($content);
            }
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x800, 8, 0, 33, $crc, strlen($packed), $size, strlen($name), 0) . $name . $packed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x800, 8, 0, 33, $crc, strlen($packed), $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    public static function status(array $metric): string
    {
        return ['met' => 'Meta atingida', 'unmet' => 'Meta não atingida', 'pending' => 'Pendente de informação', 'not_applicable' => 'Não aplicável', 'provisional' => 'Apuração provisória'][$metric['status'] ?? 'pending'] ?? 'Pendente de informação';
    }

    public static function percent($number): string
    {
        return $number === null ? 'Não apurado' : number_format((float)$number, 2, ',', '.') . '%';
    }

    public static function number($number): string
    {
        return $number === null ? 'Não informado' : number_format((float)$number, 0, ',', '.');
    }

    /** Names belong to the reporting source at extraction time, never the hosting GLPI. */
    public static function selectionRows(array $snapshot, string $kind, array $ids): array
    {
        $catalog = $snapshot['selection_catalog'][$kind] ?? [];
        $rows = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $name = (string)($catalog[$id]['name'] ?? '');
            $rows[] = [(string)$id, $name !== '' ? $name : 'Nome não registrado nesta extração'];
        }
        return $rows;
    }

    public static function selectionLabels(array $snapshot, string $kind, array $ids, string $empty): string
    {
        $labels = [];
        foreach (self::selectionRows($snapshot, $kind, $ids) as $row) {
            $labels[] = $row[0] . ' — ' . $row[1];
        }
        return $labels ? implode('; ', $labels) : $empty;
    }

    public static function kbCriterion(array $snapshot): string
    {
        return ($snapshot['input']['kb_mode'] ?? '') === 'category_proxy'
            ? 'Associação da categoria do chamado à base de conhecimento'
            : 'Vínculo direto do chamado a artigo previamente validado';
    }

    public static function duration($seconds): string
    {
        if ($seconds === null) {
            return 'Não apurado';
        }
        $seconds = (int)round($seconds);
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    public static function date($value): string
    {
        $value = (string)$value;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $value, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1] . str_replace('T', ' ', $m[4]);
        }
        return $value;
    }
}
