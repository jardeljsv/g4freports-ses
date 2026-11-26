<?php

namespace GlpiPlugin\G4freports\Ses;

/** Standalone export of entered registers; optional staff billing is calculated without saving. */
final class RegisterExporter
{
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 1048576;

    public static function filename(string $kind): string
    {
        self::checkKind($kind);
        return $kind === 'staff' ? 'Cadastro_Profissionais.xlsx' : 'Numeros_Desconsiderados.xlsx';
    }

    public static function export(string $kind, $raw, string $scope = 'all', string $month = ''): string
    {
        self::checkKind($kind);
        if (!in_array($scope, ['all', 'filtered'], true)) {
            throw new \InvalidArgumentException('Seleção de exportação inválida.');
        }
        if ($month !== '' && !preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $month)) {
            throw new \InvalidArgumentException('Mês inválido para exportação do cadastro.');
        }
        $rows = self::rows($raw, $kind);
        $staff = $kind === 'staff';
        // A phone register has no competence. Keep its interchange file identical
        // regardless of the report month currently selected in the page.
        if (!$staff) $month = '';
        $withBilling = $staff && $month !== '';
        $headers = $staff ? ['Nº', 'Nome', 'Perfil Profissional', 'Data de admissão', 'Data de saída',
            'Não Faturar', 'Valor mensal', 'Identificação do vínculo', 'ID do registro']
            : ['Número de origem', 'Identificação / motivo', 'Excluir'];
        if ($withBilling) $headers = array_merge($headers, ['Primeiro dia Faturado', 'Último dia Faturado', 'Dias Faturado', 'Valor Faturado', 'Status', 'Dias automáticos', 'Dias informados', 'Frente de serviço']);
        elseif ($staff) $headers[] = 'Frente de serviço';
        $title = $staff ? 'Cadastro de profissionais' : 'Números de chamadas desconsideradas';
        $lastColumn = chr(64 + count($headers));
        $lastDataRow = count($rows) + 3;
        $endRow = $lastDataRow + 1;
        $metadata = ($scope === 'filtered' ? 'Registros da seleção filtrada' : 'Todos os registros da tabela')
            . ' · ' . count($rows) . ' registro(s). Valores da tela, incluindo alterações ainda não salvas. A exportação não salva o cadastro.';
        if ($staff) {
            $metadata .= ' Para importar, mantenha os títulos e IDs. Edite os dados do cadastro';
            if ($withBilling) $metadata .= ' e Dias informados de ' . substr($month, 5, 2) . '/' . substr($month, 0, 4);
            $metadata .= '; os campos calculados serão refeitos. Os ajustes de ' . ($withBilling ? 'outros meses' : 'dias por mês')
                . ' estão na aba Dias por competência.';
            if ($withBilling) $metadata .= ' Dias informados em branco restaura o cálculo automático desta competência. Não Faturar exclui dias e valor.';
        } else {
            $metadata .= ' Para importar, mantenha os títulos. Edite número, identificação e exclusão. Números devem permanecer como texto para preservar os zeros iniciais.';
        }
        $sheet = self::xmlHeader() . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' . $lastColumn . $endRow . '"/><sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            . '<pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A4" sqref="A4"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/><cols>';
        $widths = $staff ? [9, 40, 52, 20, 20, 16, 20, 28, 38] : [30, 64, 16];
        if ($withBilling) $widths = array_merge($widths, [23, 23, 18, 22, 18, 18, 18, 30]);
        elseif ($staff) $widths[] = 30;
        foreach ($widths as $i => $width) {
            $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData><row r="1" ht="32" customHeight="1">' . self::cell('A1', $title, 1)
            . '</row><row r="2" ht="' . ($withBilling ? 72 : 64) . '" customHeight="1">' . self::cell('A2', $metadata, 2) . '</row><row r="3" ht="32" customHeight="1">';
        foreach ($headers as $i => $header) $sheet .= self::cell(chr(65 + $i) . '3', $header, 3);
        $sheet .= '</row>';
        $billedDaysTotal = $billedCentsTotal = 0;
        foreach ($rows as $i => $row) {
            $r = $i + 4;
            $baseStyle = $i % 2 === 0 ? 4 : 5;
            $values = $staff ? [$i + 1, $row['name'], $row['profile'],
                $row['admission_date'], $row['departure_date'], StaffRegister::isNotBilled($row) ? 'Sim' : 'Não',
                $row['monthly_value'], $row['record_reference'], $row['id']]
                : [$row['number'], $row['label'], $row['active'] ? 'Sim' : 'Não'];
            if ($withBilling) {
                // Only raw entered fields are accepted by rows(); posted calculated values cannot override billing.
                $billing = StaffRegister::billingForMonth($row, $month);
                $values = array_merge($values, [$billing['billing_first_day'] ?? '', $billing['billing_last_day'] ?? '',
                    $billing['billed_days'] ?? '', $billing['billed_value'] ?? '', $billing['status'],
                    $billing['automatic_billed_days'] ?? '', $row['billing_days_overrides'][$month] ?? '', $row['service_front'] ?? '']);
                $billedDaysTotal += (int)($billing['billed_days'] ?? 0);
                if ($billing['billed_value'] !== null) $billedCentsTotal += (int)round((float)$billing['billed_value'] * 100);
            } elseif ($staff) $values[] = $row['service_front'];
            $sheet .= '<row r="' . $r . '" ht="' . self::rowHeight($values, $widths) . '" customHeight="1">';
            foreach ($values as $col => $value) {
                $style = $baseStyle;
                $numeric = null;
                if ($staff && (in_array($col, [3, 4], true) || ($withBilling && in_array($col, [9, 10], true)))) {
                    $numeric = self::dateNumber($value);
                    if ($numeric !== null) $style = $i % 2 === 0 ? 6 : 7;
                } elseif ($staff && in_array($col, [6, 12], true)) {
                    $numeric = self::moneyNumber($value);
                    if ($numeric !== null) $style = $i % 2 === 0 ? 8 : 9;
                } elseif ($staff && in_array($col, [0, 11, 14, 15], true) && is_int($value)) {
                    $numeric = $value;
                }
                $sheet .= self::cell(chr(65 + $col) . $r, $numeric ?? $value, $style, $numeric !== null);
            }
            $sheet .= '</row>';
        }
        $sheet .= '<row r="' . $endRow . '" ht="24" customHeight="1">'
            . self::cell('A' . $endRow, 'Total de registros', 10) . self::cell('B' . $endRow, count($rows), 10, true)
            . ($withBilling ? self::cell('K' . $endRow, 'Totais faturados', 10)
                . self::cell('L' . $endRow, $billedDaysTotal, 10, true)
                . self::cell('M' . $endRow, number_format($billedCentsTotal / 100, 2, '.', ''), 11, true) : '') . '</row>'
            . '</sheetData><autoFilter ref="A3:' . $lastColumn . $lastDataRow . '"/>'
            . '<mergeCells count="2"><mergeCell ref="A1:' . $lastColumn . '1"/><mergeCell ref="A2:' . $lastColumn . '2"/></mergeCells>'
            . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
        // Hidden interchange controls preserve metadata that is no longer shown
        // in the register without reintroducing it into the user-facing table.
        $controlRows = [['g4freports-register-v1', $kind, $month], ['ID do registro', 'Motivo de saída']];
        $overrideRows = [['ID do registro', 'Competência', 'Dias informados']];
        if ($staff) foreach ($rows as $row) {
            $controlRows[] = [$row['id'], $row['departure_reason'] !== '' ? $row['departure_reason'] : 'pending'];
            $overrides = $row['billing_days_overrides'];
            ksort($overrides);
            foreach ($overrides as $competence => $days) {
                // The selected month's editable value already lives in P of
                // the first sheet. Export it only once, including explicit zero.
                if ($competence !== $month) $overrideRows[] = [$row['id'], $competence, $days];
            }
        }
        $sheets = [
            ['name' => $staff ? 'Profissionais' : 'Números desconsiderados', 'xml' => $sheet, 'hidden' => false],
            ['name' => 'Controle', 'xml' => self::simpleSheet($controlRows, [40, 30, 20], 2), 'hidden' => true],
        ];
        if ($staff) $sheets[] = ['name' => 'Dias por competência', 'xml' => self::simpleSheet($overrideRows, [40, 24, 24]), 'hidden' => false];
        $types = $sheetDefinitions = $relationships = '';
        $files = [];
        foreach ($sheets as $i => $definition) {
            $id = $i + 1;
            $path = 'worksheets/sheet' . $id . '.xml';
            $types .= '<Override PartName="/xl/' . $path . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetDefinitions .= '<sheet name="' . OfficePackage::xml($definition['name']) . '" sheetId="' . $id . '"'
                . ($definition['hidden'] ? ' state="hidden"' : '') . ' r:id="rId' . $id . '"/>';
            $relationships .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $path . '"/>';
            $files['xl/' . $path] = $definition['xml'];
        }
        $styleId = count($sheets) + 1;
        return OfficePackage::write([
            '[Content_Types].xml' => self::xmlHeader() . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . $types . '</Types>',
            '_rels/.rels' => self::xmlHeader() . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => self::xmlHeader() . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<bookViews><workbookView/></bookViews><sheets>' . $sheetDefinitions . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => self::xmlHeader() . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . $relationships . '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => self::styles(),
        ] + $files);
    }

    /** Compact editable auxiliary table; all identifiers stay literal text. */
    private static function simpleSheet(array $rows, array $widths, int $headerRow = 1): string
    {
        $lastColumn = chr(64 + count($widths));
        $endRow = count($rows);
        $nextRow = $headerRow + 1;
        $xml = self::xmlHeader() . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' . $lastColumn . $endRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $headerRow
            . '" topLeftCell="A' . $nextRow . '" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A' . $nextRow . '" sqref="A' . $nextRow . '"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/><cols>';
        foreach ($widths as $i => $width) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        $xml .= '</cols><sheetData>';
        foreach ($rows as $i => $values) {
            $r = $i + 1;
            $style = $r === $headerRow ? 3 : ($i % 2 === 0 ? 4 : 5);
            $xml .= '<row r="' . $r . '" ht="' . self::rowHeight($values, $widths) . '" customHeight="1">';
            foreach ($values as $column => $value) $xml .= self::cell(chr(65 + $column) . $r, $value, $style, is_int($value));
            $xml .= '</row>';
        }
        return $xml . '</sheetData><autoFilter ref="A' . $headerRow . ':' . $lastColumn . $endRow . '"/>'
            . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="portrait" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    /** Export accepts incomplete input rows and never invokes register save validation. */
    private static function rows($raw, string $kind): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > self::MAX_BYTES || substr(ltrim($raw), 0, 1) !== '[') {
                throw new \InvalidArgumentException('A exportação aceita uma lista JSON de até 1 MB.');
            }
            $raw = json_decode($raw, true, 16);
            if (json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException('Dados de exportação inválidos.');
        }
        if (!is_array($raw) || ($raw && array_keys($raw) !== range(0, count($raw) - 1)) || count($raw) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('A exportação aceita até 500 registros.');
        }
        $fields = $kind === 'staff' ? ['id', 'name', 'profile', 'admission_date', 'departure_date', 'departure_reason', 'monthly_value', 'record_reference', 'service_front']
            : ['number', 'label'];
        $rows = [];
        foreach ($raw as $source) {
            if (!is_array($source) || ($source && array_keys($source) === range(0, count($source) - 1))) {
                throw new \InvalidArgumentException('Cada registro deve ser um objeto de campos.');
            }
            $row = [];
            foreach ($fields as $field) {
                $value = $source[$field] ?? '';
                if ((!is_string($value) && !is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value)) || strlen((string)$value) > 2048) {
                    throw new \InvalidArgumentException('Campo de exportação inválido ou muito extenso: ' . $field . '.');
                }
                $row[$field] = (string)$value;
            }
            if ($kind === 'staff') {
                $row['not_billed'] = StaffRegister::isNotBilled($source);
                $overrides = $source['billing_days_overrides'] ?? [];
                // The saved schema permits every month from January 2000 through December 2100.
                if (!is_array($overrides) || count($overrides) > 1212) throw new \InvalidArgumentException('Dias informados inválidos.');
                foreach ($overrides as $key => $days) {
                    if ($days === null || $days === '') { unset($overrides[$key]); continue; }
                    if (!preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', (string)$key) || (!is_int($days) && !(is_string($days) && preg_match('/\A(?:0|[1-9]\d*)\z/D', $days))) || (int)$days < 0 || (int)$days > 31) {
                        throw new \InvalidArgumentException('Dias informados inválidos.');
                    }
                    $overrides[$key] = (int)$days;
                }
                $row['billing_days_overrides'] = $overrides;
            }
            else {
                $active = $source['active'] ?? true;
                if (!in_array($active, [true, false, 1, 0, '1', '0'], true)) {
                    throw new \InvalidArgumentException('Opção de exclusão inválida.');
                }
                $row['active'] = in_array($active, [true, 1, '1'], true);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function checkKind(string $kind): void
    {
        if (!in_array($kind, ['staff', 'tests'], true)) throw new \InvalidArgumentException('Cadastro desconhecido.');
    }

    private static function dateNumber(string $date): ?int
    {
        if (!preg_match('/\A([2-9]\d{3}|19\d{2})-(\d{2})-(\d{2})\z/D', $date, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        $parsed = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        $origin = new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC'));
        $serial = (int)$origin->diff($parsed)->days;
        // Excel counts a fictional 1900-02-29; real dates before it need one less.
        return $date < '1900-03-01' ? $serial - 1 : $serial;
    }

    private static function moneyNumber(string $money): ?string
    {
        $money = trim($money);
        if (preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/D', $money)) return $money;
        if (preg_match('/\A(?:\d{1,12}|\d{1,3}(?:\.\d{3}){1,3}),\d{1,2}\z/D', $money)) {
            return str_replace(',', '.', str_replace('.', '', $money));
        }
        return null;
    }

    private static function cell(string $ref, $value, int $style, bool $number = false): string
    {
        if ($number) return '<c r="' . $ref . '" s="' . $style . '" t="n"><v>' . OfficePackage::xml($value) . '</v></c>';
        // Always inline text: =, +, -, @ and phone prefixes never become formulas.
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . OfficePackage::xml($value) . '</t></is></c>';
    }

    private static function rowHeight(array $values, array $widths): int
    {
        $lineCount = 1;
        foreach ($values as $column => $value) {
            // Widths are Excel character units; leave padding and allow for
            // proportional fonts and line wrapping at word boundaries.
            $capacity = max(5, (int)floor(($widths[$column] - 2) * 0.82));
            $lines = 0;
            foreach (preg_split('/\r\n|\r|\n/', (string)$value) as $line) {
                $length = function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : preg_match_all('/./us', $line);
                $lines += max(1, (int)ceil($length / $capacity));
            }
            $lineCount = max($lineCount, $lines);
        }
        return min(240, max(32, 16 * $lineCount + 8));
    }

    private static function xmlHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    }

    private static function styles(): string
    {
        $xml = self::xmlHeader() . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="&quot;R$&quot; #,##0.00"/></numFmts>'
            . '<fonts count="4"><font><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Calibri"/></font></fonts>'
            . '<fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ([ReportStyle::NAVY, ReportStyle::BLUE, ReportStyle::WHITE, ReportStyle::LIGHT_GRAY] as $color) {
            $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $xml .= '</fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="12">';
        // normal, title, metadata, header, alternating body/date/currency, total.
        foreach ([[0, 0, 0], [1, 2, 0], [0, 5, 0], [2, 3, 0], [0, 4, 0], [0, 5, 0],
            [0, 4, 164], [0, 5, 164], [0, 4, 165], [0, 5, 165], [3, 5, 0], [3, 5, 165]] as [$font, $fill, $format]) {
            $xml .= '<xf numFmtId="' . $format . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="0" xfId="0" applyAlignment="1"'
                . ($format ? ' applyNumberFormat="1"' : '') . '><alignment vertical="center" wrapText="1"/></xf>';
        }
        return $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
