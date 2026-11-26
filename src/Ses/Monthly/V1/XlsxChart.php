<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** Native editable spreadsheet chart parts with references to visible source cells. */
final class XlsxChart
{
    public static function anchor(array $chart, string $relationship, int $id): string
    {
        [$left, $top, $right, $bottom] = $chart['anchor'];
        $xml = '<xdr:twoCellAnchor editAs="oneCell">';
        foreach (['from' => [$left, $top], 'to' => [$right, $bottom]] as $tag => $position) {
            $xml .= '<xdr:' . $tag . '><xdr:col>' . $position[0] . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $position[1] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:' . $tag . '>';
        }
        return $xml . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="' . $id . '" name="' . OfficePackage::xml($chart['title']) . '"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="' . $relationship . '"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
    }

    public static function xml(array $chart): string
    {
        $kind = $chart['kind'];
        $circular = in_array($kind, ['pie', 'doughnut'], true);
        $line = $kind === 'line';
        $horizontal = $kind === 'bar';
        $percent = $chart['unit'] === 'percent';
        $format = OfficePackage::xml($chart['value_format'] ?? ($percent ? '0.00%' : '#,##0'));
        $dateAxis = $line && !empty($chart['date_axis']);
        $showLabels = ($chart['show_data_labels'] ?? true) !== false;
        $count = count($chart['categories']);
        $dense = $count > 12;
        $maximum = 0;
        foreach ($chart['series'] as $series) { foreach ($series['values'] as $value) { if ($value !== null) { $maximum = max($maximum, $value); } } }
        if (!empty($chart['stacked'])) {
            for ($i = 0; $i < $count; $i++) {
                $sum = 0; foreach ($chart['series'] as $series) { $sum += $series['values'][$i] ?? 0; }
                $maximum = max($maximum, $sum);
            }
        }
        $maximum = $percent ? max(0.05, ceil($maximum * 110) / 100) : max(1, ceil($maximum * 1.15));
        $majorUnit = !$percent && $maximum <= 20 ? max(1, ceil($maximum / 6)) : null;
        $minimum = 0;
        if (isset($chart['value_format']) || isset($chart['axis_min']) || isset($chart['axis_max']) || !empty($chart['signed'])) {
            [$minimum, $maximum] = self::valueBounds($chart);
            $majorUnit = null;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><c:lang val="pt-BR"/><c:roundedCorners val="0"/><c:chart><c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1250" b="1"/></a:pPr><a:r><a:rPr lang="pt-BR"/><a:t>' . OfficePackage::xml($chart['title']) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/><c:plotArea><c:layout/>';
        $chartTag = $circular ? ($kind . 'Chart') : ($line ? 'lineChart' : 'barChart');
        $xml .= '<c:' . $chartTag . '>';
        if (!$circular && !$line) { $xml .= '<c:barDir val="' . ($horizontal ? 'bar' : 'col') . '"/>'; }
        if (!$circular) { $xml .= '<c:grouping val="' . ($line ? 'standard' : (!empty($chart['stacked']) ? 'stacked' : 'clustered')) . '"/>'; }
        $xml .= '<c:varyColors val="' . ($circular ? '1' : '0') . '"/>';
        foreach ($chart['series'] as $index => $series) {
            $color = preg_match('/^[0-9A-Fa-f]{6}$/', $series['color'] ?? '') ? $series['color'] : ReportStyle::BLUE;
            $xml .= '<c:ser><c:idx val="' . $index . '"/><c:order val="' . $index . '"/><c:tx><c:v>' . OfficePackage::xml($series['name']) . '</c:v></c:tx>';
            $lineOptions = array_intersect(['line_visible', 'line_dash', 'line_width', 'marker', 'marker_size'], array_keys($series));
            if ($line && $lineOptions) {
                $xml .= self::lineStyle($series, $color);
            } else {
            $xml .= '<c:spPr>';
            $xml .= $line ? '<a:ln w="25400"><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:ln>' : '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln>';
            $xml .= '</c:spPr>';
            if ($line) { $xml .= '<c:marker><c:symbol val="circle"/><c:size val="4"/><c:spPr><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr></c:marker>'; }
            }
            if (count($chart['series']) === 1 && !empty($chart['point_colors'])) {
                foreach ($chart['point_colors'] as $point => $pointColor) {
                    $xml .= '<c:dPt><c:idx val="' . $point . '"/><c:spPr><a:solidFill><a:srgbClr val="' . $pointColor . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr></c:dPt>';
                }
            }
            // Office requires custom formatting and per-point labels inside ser, before cat/val.
            if ($circular && $showLabels) {
                // Small/zero slices retain their legend and exact adjacent source counts.
                $sum = array_sum($series['values']);
                $xml .= '<c:dLbls>';
                foreach ($series['values'] as $point => $value) {
                    $show = $value !== null && $sum > 0 && $value / $sum >= 0.05;
                    // CT_DLbl requires either delete or label settings, never both.
                    $xml .= '<c:dLbl><c:idx val="' . $point . '"/>';
                    $xml .= $show
                        ? '<c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/>'
                        : '<c:delete val="1"/>';
                    $xml .= '</c:dLbl>';
                }
                $xml .= '<c:numFmt formatCode="0%" sourceLinked="0"/><c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1400" b="1"><a:solidFill><a:srgbClr val="' . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:endParaRPr lang="pt-BR"/></a:p></c:txPr>'
                    // Doughnut series use automatic centering; an explicit position is unsupported.
                    . ($kind === 'doughnut' ? '' : '<c:dLblPos val="ctr"/>')
                    . '<c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/><c:showLeaderLines val="0"/></c:dLbls>';
            } elseif ($showLabels && !$dense) {
                $xml .= '<c:dLbls><c:numFmt formatCode="' . $format . '" sourceLinked="0"/><c:dLblPos val="' . ($line ? 't' : (!empty($chart['stacked']) ? 'ctr' : 'outEnd')) . '"/><c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/><c:showSerName val="0"/></c:dLbls>';
            }
            // A metric comparison can use its immutable INS identifier as a
            // literal category while both values remain linked to editable cells.
            $hasCategoryReference = isset($chart['category_ref']) && $chart['category_ref'] !== '';
            $cacheType = $dateAxis ? 'num' : 'str';
            $xml .= '<c:cat>' . ($hasCategoryReference
                ? '<c:' . $cacheType . 'Ref><c:f>' . OfficePackage::xml($chart['category_ref']) . '</c:f><c:' . $cacheType . 'Cache>'
                : '<c:' . $cacheType . 'Lit>') . ($dateAxis ? '<c:formatCode>' . OfficePackage::xml($chart['category_format'] ?? 'mmm/yy') . '</c:formatCode>' : '') . '<c:ptCount val="' . $count . '"/>';
            foreach ($chart['categories'] as $point => $label) { $xml .= '<c:pt idx="' . $point . '"><c:v>' . ($dateAxis ? self::dateSerial($label) : OfficePackage::xml($label)) . '</c:v></c:pt>'; }
            $xml .= ($hasCategoryReference ? '</c:' . $cacheType . 'Cache></c:' . $cacheType . 'Ref>' : '</c:' . $cacheType . 'Lit>')
                . '</c:cat><c:val><c:numRef><c:f>' . OfficePackage::xml($series['value_ref']) . '</c:f><c:numCache><c:formatCode>' . $format . '</c:formatCode><c:ptCount val="' . $count . '"/>';
            foreach ($series['values'] as $point => $value) {
                if ($value !== null) { $xml .= '<c:pt idx="' . $point . '"><c:v>' . sprintf('%.15g', $value) . '</c:v></c:pt>'; }
            }
            $xml .= '</c:numCache></c:numRef></c:val>' . ($line ? '<c:smooth val="0"/>' : '') . '</c:ser>';
        }
        if ($circular) {
            $xml .= '<c:firstSliceAng val="270"/>';
            if ($kind === 'doughnut') { $xml .= '<c:holeSize val="60"/>'; }
        }
        if (!$circular && !$line) { $xml .= '<c:gapWidth val="' . ($dense ? '45' : '65') . '"/><c:overlap val="' . (!empty($chart['stacked']) ? '100' : '0') . '"/>'; }
        if ($line) { $xml .= '<c:marker val="1"/><c:smooth val="0"/>'; }
        if (!$circular) { $xml .= '<c:axId val="100"/><c:axId val="200"/>'; }
        $xml .= '</c:' . $chartTag . '>';
        if (!$circular) {
            if ($dateAxis) {
                $xml .= self::dateAxis($chart, 100, 200);
            } else {
            $xml .= '<c:catAx><c:axId val="100"/><c:scaling><c:orientation val="' . ($horizontal ? 'maxMin' : 'minMax') . '"/></c:scaling><c:delete val="0"/><c:axPos val="' . ($horizontal ? 'l' : 'b') . '"/>' . self::axisTitle($chart['axis_category_title'] ?? ($percent ? 'Indicador' : 'Categoria')) . '<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:spPr><a:ln><a:noFill/></a:ln></c:spPr>' . self::textProperties($count > 20 ? 850 : 1050) . '<c:crossAx val="200"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/>'
                . ($count <= 12 ? '<c:tickLblSkip val="1"/><c:tickMarkSkip val="1"/>' : '')
                . '<c:noMultiLvlLbl val="1"/></c:catAx>';
            }
            $xml .= '<c:valAx><c:axId val="200"/><c:scaling><c:orientation val="minMax"/><c:max val="' . sprintf('%.15g', $maximum) . '"/><c:min val="' . sprintf('%.15g', $minimum) . '"/></c:scaling><c:axPos val="' . ($horizontal ? 'b' : 'l') . '"/><c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="' . ReportStyle::LIGHT_GRAY . '"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>' . self::axisTitle($chart['axis_value_title'] ?? ($percent ? 'Percentual (%)' : 'Quantidade')) . '<c:numFmt formatCode="' . OfficePackage::xml($chart['value_format'] ?? ($percent ? '0%' : '#,##0')) . '" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:spPr><a:ln><a:noFill/></a:ln></c:spPr><c:crossAx val="100"/><c:crosses val="' . ($horizontal ? 'max' : 'autoZero') . '"/><c:crossBetween val="' . ($line ? 'midCat' : 'between') . '"/>' . ($majorUnit !== null ? '<c:majorUnit val="' . $majorUnit . '"/>' : '') . '</c:valAx>';
        }
        $xml .= '</c:plotArea>';
        if (($chart['show_legend'] ?? true) !== false && ($circular || count($chart['series']) > 1)) { $xml .= '<c:legend><c:legendPos val="b"/>' . self::legendEntries($chart) . '<c:overlay val="0"/>' . self::textProperties(1000) . '</c:legend>'; }
        return $xml . '<c:plotVisOnly val="' . (($chart['plot_visible_only'] ?? true) === false ? '0' : '1') . '"/><c:dispBlanksAs val="gap"/><c:showDLblsOverMax val="0"/></c:chart><c:spPr><a:solidFill><a:srgbClr val="' . ReportStyle::WHITE . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr><c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000"><a:solidFill><a:srgbClr val="' . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:endParaRPr lang="pt-BR"/></a:p></c:txPr></c:chartSpace>';
    }

    /** Excel 1900-system serial; strict ISO dates prevent local-time or normalization drift. */
    public static function dateSerial(string $iso): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $iso || $iso < '1900-03-01' || $iso > '9999-12-31') {
            throw new \InvalidArgumentException('Data inválida no eixo temporal do gráfico.');
        }
        return (int)($date->getTimestamp() / 86400) + 25569;
    }

    /** Shared opt-in native line/marker styling for Word and Excel. */
    public static function lineStyle(array $series, string $color): string
    {
        $visible = ($series['line_visible'] ?? true) !== false;
        $width = max(1000, min(100000, (int)($series['line_width'] ?? 25400)));
        $dash = $series['line_dash'] ?? 'solid';
        if (!in_array($dash, ['solid', 'dash', 'dot', 'dashDot', 'lgDash', 'lgDashDot', 'sysDash', 'sysDot'], true)) $dash = 'solid';
        $marker = $series['marker'] ?? 'circle';
        if (!in_array($marker, ['circle', 'diamond', 'square', 'triangle', 'x', 'plus', 'none'], true)) $marker = 'circle';
        $size = max(2, min(72, (int)($series['marker_size'] ?? 4)));
        $stroke = $visible ? '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:prstDash val="' . $dash . '"/>' : '<a:noFill/>';
        return '<c:spPr><a:ln w="' . $width . '">' . $stroke . '</a:ln></c:spPr><c:marker><c:symbol val="' . $marker . '"/><c:size val="' . $size . '"/><c:spPr><a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr></c:marker>';
    }

    /** Hide duplicate segment labels without creating misleading connecting lines. */
    public static function legendEntries(array $chart): string
    {
        $xml = '';
        foreach ($chart['series'] as $index => $series) {
            if (($series['show_legend'] ?? true) === false) $xml .= '<c:legendEntry><c:idx val="' . $index . '"/><c:delete val="1"/></c:legendEntry>';
        }
        return $xml;
    }

    /** Real-date positions retain sparse selection gaps without materializing intervening months. */
    public static function dateAxis(array $chart, int $categoryId, int $valueId): string
    {
        foreach ($chart['categories'] as $date) self::dateSerial($date);
        $months = array_map(static fn(string $date): int => (int)substr($date, 0, 4) * 12 + (int)substr($date, 5, 2), $chart['categories']);
        $span = $months ? max($months) - min($months) : 0;
        $step = max(1, (int)ceil($span / 11));
        return '<c:dateAx><c:axId val="' . $categoryId . '"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/>' . self::axisTitle($chart['axis_category_title'] ?? '') . '<c:numFmt formatCode="' . OfficePackage::xml($chart['category_format'] ?? 'mmm/yy') . '" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . self::textProperties(950) . '<c:crossAx val="' . $valueId . '"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblOffset val="100"/><c:baseTimeUnit val="days"/><c:majorUnit val="' . $step . '"/><c:majorTimeUnit val="months"/></c:dateAx>';
    }

    /** Explicit opt-in bounds allow signed money and Excel-day duration units. */
    public static function valueBounds(array $chart): array
    {
        $minimum = 0.0; $maximum = 0.0;
        foreach ($chart['series'] as $series) {
            foreach ($series['values'] as $value) {
                if ($value !== null && is_numeric($value) && is_finite((float)$value)) {
                    $minimum = min($minimum, (float)$value); $maximum = max($maximum, (float)$value);
                }
            }
        }
        if (empty($chart['signed'])) $minimum = 0.0;
        $minimum = $minimum < 0 ? $minimum * 1.1 : 0.0;
        $maximum = $maximum > 0 ? $maximum * 1.1 : ($minimum < 0 ? 0.0 : 1.0);
        if (isset($chart['axis_min'])) $minimum = (float)$chart['axis_min'];
        if (isset($chart['axis_max'])) $maximum = (float)$chart['axis_max'];
        if (!is_finite($minimum) || !is_finite($maximum) || $minimum >= $maximum) throw new \InvalidArgumentException('Limites inválidos no eixo do gráfico.');
        return [$minimum, $maximum];
    }

    /** CT_CatAx/CT_ValAx use the same rich-text title as the chart. */
    private static function axisTitle(string $text): string
    {
        if ($text === '') return '';
        return '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000"><a:solidFill><a:srgbClr val="'
            . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:r><a:t>'
            . OfficePackage::xml($text) . '</a:t></a:r></a:p></c:rich></c:tx><c:layout/><c:overlay val="0"/></c:title>';
    }

    private static function textProperties(int $size): string
    {
        return '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="' . $size . '"><a:solidFill><a:srgbClr val="' . ReportStyle::NAVY . '"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr></a:pPr><a:endParaRPr lang="pt-BR"/></a:p></c:txPr>';
    }
}
