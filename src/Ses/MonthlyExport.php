<?php

namespace GlpiPlugin\G4freports\Ses;

use GlpiPlugin\G4freports\Ses\Monthly\V1\Profile;
use GlpiPlugin\G4freports\Ses\Monthly\V1\DocxExporter as MonthlyWord;
use GlpiPlugin\G4freports\Ses\Monthly\V1\XlsxExporter as MonthlyExcel;

/** One rendering contract for temporary and archived full monthly snapshots. */
final class MonthlyExport
{
    private const REVIEW_PROFILE = 'ses-monthly-v2';
    private const REFINED_PROFILE = 'ses-monthly-v3';
    /** Stamp only newly generated data, before its draft/revision fingerprint. */
    public static function stamp(array $snapshot): array
    {
        self::assertSchema($snapshot);
        if (array_key_exists('monthly_export', $snapshot)) {
            self::profile($snapshot);
            return $snapshot;
        }
        $snapshot['monthly_export'] = ['profile' => array_key_exists('severity_review', $snapshot) ? self::REFINED_PROFILE : Profile::ID];
        return $snapshot;
    }

    /**
     * Untagged schema-2/v16 reports use the verified 1.8.23 monthly baseline.
     * This is a compatibility mapping, not a claim of recorded historical layout.
     * No filename, PHP class or template supplied by a request is ever loaded.
     */
    public static function profile(array $snapshot): string
    {
        self::assertSchema($snapshot);
        if (!array_key_exists('monthly_export', $snapshot)) return Profile::ID;
        $metadata = $snapshot['monthly_export'];
        if (!is_array($metadata) || array_keys($metadata) !== ['profile']
            || !in_array($metadata['profile'] ?? null, [Profile::ID, self::REVIEW_PROFILE, self::REFINED_PROFILE], true)
            || (in_array($metadata['profile'], [self::REVIEW_PROFILE, self::REFINED_PROFILE], true) && !is_array($snapshot['severity_review'] ?? null))) {
            throw new \RuntimeException('O formato mensal registrado nesta revisão não é compatível com esta instalação. Os dados JSON continuam disponíveis; nenhuma nova apuração foi realizada.', 409);
        }
        return $metadata['profile'];
    }

    /** @return array{binary:string,filename:string,mime:string,profile:string,legacy:bool} */
    public static function render(array $snapshot, string $format): array
    {
        if (!in_array($format, ['docx', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Selecione Word ou Excel para exportar o relatório mensal.');
        }
        $profile = self::profile($snapshot);
        Profile::assertInstalled();
        // The fixed registry deliberately has no dynamic class/path lookup.
        $exporter = $format === 'docx'
            ? ($profile === self::REFINED_PROFILE ? new Monthly\V3\DocxExporter() : ($profile === self::REVIEW_PROFILE ? new Monthly\V2\DocxExporter() : new MonthlyWord()))
            : new MonthlyExcel();
        return [
            'binary' => $exporter->toBinary($snapshot),
            'filename' => $exporter->filename($snapshot),
            'mime' => $format === 'docx'
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'profile' => $profile,
            'legacy' => !array_key_exists('monthly_export', $snapshot),
        ];
    }

    private static function assertSchema(array $snapshot): void
    {
        if (($snapshot['schema_version'] ?? null) !== Profile::SCHEMA
            || ($snapshot['rules_version'] ?? null) !== Profile::RULES) {
            throw new \RuntimeException('A estrutura ou as regras desta revisão não possuem um formato mensal compatível nesta instalação. Os dados JSON continuam disponíveis; nenhuma nova apuração foi realizada.', 409);
        }
    }
}
