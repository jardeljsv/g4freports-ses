<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/**
 * Frozen normal monthly presentation from 1.8.23 (schema 2 / calculation rules v16).
 * A future output-changing adjustment belongs in a new presentation profile.
 * Security fixes must retain output compatibility and update the installation hashes.
 */
final class Profile
{
    public const ID = 'ses-monthly-v1';
    public const RULES = 'ses-2026-10-05-v16';
    public const SCHEMA = 2;
    public const TEMPLATE_SHA256 = '67154bd847e58ac0d8d802bee053c94a094bcf36b7f2db3a819967fbb1b6a0cb';
    private const FILES = [
        'DocxExporter.php' => '43a5037de8c3f9ab567050afadb00546714f63d08b7a7e2cd6cd2b0ab099b025',
        'XlsxExporter.php' => 'e4251f88da18515ca5f03a83d4b804256f1676ac624cd6ba51964e9af8de0c92',
        'ChartData.php' => '7dd455f84a40e1189ca573a120045da1587900390b53bb770e05a69e225c088c',
        'HeatmapData.php' => '7535464594701a879f392aa8c0036b7d767868bdb9597a8992f568b05bdf1c34',
        'MetricEvidence.php' => '5ee5fbd21845200800aff63cfb30ab996d128ac27aa4aec4539456e2faf59aa9',
        'ReportStyle.php' => '1c733199361e114be3a28a62193a709c0a9bdad0e56be37c9c9b5d4051b4619c',
        'WordChart.php' => '6a54ce57512cd5e104633fe6c8c020abc876a8397ceccafaee73b08a4d8c62f3',
        'XlsxChart.php' => 'fb30cc19c0bc872c45384fe0e7d7860c132cd120f89fa35f5cdfebe9dedb994f',
        'OfficePackage.php' => '73b7902a8dd0ac4d0ac167d218ef6657f20705c7fc1df8eee102409484dc6ffb',
        'StaffRegister.php' => '4d1b4cf3933988fe5dc37539084111b9043980e0ecd186aac25128504e7ae76f',
        'FinanceSettings.php' => '1ec49233be1042051f3cb891fca9afff500706d14d1daed7dfa14f69a76d5500',
        'PhoneSurveySummary.php' => 'e3d70d7d8e45523d0c8481e93b967d405bc148b922a097b363414d3d3da4f59d',
        'PhoneSurveyScore.php' => '055d456aa0f1d7e3ad3e4e071b2f10d7edd3928d8c054bc4ce76ce9e44aa52e1',
    ];

    public static function assertInstalled(): void
    {
        foreach (self::FILES as $file => $expected) {
            $path = __DIR__ . '/' . $file;
            $actual = is_file($path) && !is_link($path) ? @hash_file('sha256', $path) : false;
            if (!is_string($actual) || !hash_equals($expected, $actual)) self::unavailable();
        }
        $template = dirname(__DIR__, 4) . '/resources/monthly/v1/papel_timbrado_G4F.docx';
        $actual = is_file($template) && !is_link($template) ? @hash_file('sha256', $template) : false;
        if (!is_string($actual) || !hash_equals(self::TEMPLATE_SHA256, $actual)) self::unavailable();
    }

    private static function unavailable(): void
    {
        throw new \RuntimeException('O formato mensal preservado está incompleto ou foi alterado na instalação. Reinstale o pacote íntegro do plugin. Os dados JSON continuam disponíveis.', 503);
    }
}
