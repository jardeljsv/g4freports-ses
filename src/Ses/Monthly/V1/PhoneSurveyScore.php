<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** Resolve both source fields once for the report's monthly call population. */
final class PhoneSurveyScore
{
    public static function validRating($value): ?int
    {
        return is_numeric($value) && in_array((float)$value, [1.0, 2.0, 3.0, 4.0, 5.0], true)
            ? (int)$value : null;
    }

    public static function hasResponse(array $call): bool
    {
        $fields = array_key_exists('satisfaction_raw', $call) || array_key_exists('satisfaction_nota_raw', $call)
            ? ['satisfaction_raw', 'satisfaction_nota_raw'] : ['satisfaction'];
        foreach ($fields as $field) {
            if (isset($call[$field]) && trim((string)$call[$field]) !== '') return true;
        }
        return false;
    }

    public static function rating(array $call): ?int
    {
        // Resolved nota is already on the business scale. Never invert it again.
        if (array_key_exists('satisfaction_source', $call)) return self::validRating($call['satisfaction'] ?? null);
        if (array_key_exists('satisfaction_raw', $call)) {
            $raw = self::validRating($call['satisfaction_raw']);
            return $raw === null ? null : 6 - $raw;
        }
        // Compatibility with evidence imported with an already-converted score.
        return self::validRating($call['satisfaction'] ?? null);
    }

    public static function resolve(array $calls): array
    {
        $pairs = [];
        foreach ($calls as $call) {
            $key = self::validRating($call['satisfaction_raw'] ?? null);
            $nota = self::validRating($call['satisfaction_nota_raw'] ?? null);
            // Sets, rather than frequencies, avoid weighting repeated queue rows.
            if ($key !== null && $nota !== null) $pairs[$key][$nota] = true;
        }
        $ambiguous = [];
        foreach ($pairs as $key => $notas) {
            if (count($notas) > 1) $ambiguous[] = (int)$key;
        }
        sort($ambiguous, SORT_NUMERIC);
        $preferNota = count($ambiguous) > 0;
        foreach ($calls as &$call) {
            $key = self::validRating($call['satisfaction_raw'] ?? null);
            $nota = self::validRating($call['satisfaction_nota_raw'] ?? null);
            $legacy = !array_key_exists('satisfaction_raw', $call) && !array_key_exists('satisfaction_nota_raw', $call);
            if ($legacy) {
                $score = self::validRating($call['satisfaction'] ?? null);
                $source = $score === null ? 'none' : 'legacy';
                $reason = 'Evidência anterior com nota já convertida; valor preservado sem nova inversão.';
            } elseif ($nota !== null && ($preferNota || $key === null)) {
                $score = $nota;
                $source = 'nota';
                $reason = $preferNota
                    ? 'Nota já tratada, sem inversão: o mesmo atendimento corresponde a notas distintas no recorte mensal.'
                    : 'Nota já tratada, sem inversão: atendimento ausente ou inválido nesta resposta.';
            } elseif ($key !== null) {
                $score = 6 - $key;
                $source = 'atendimento';
                $reason = $preferNota
                    ? 'Nota ausente ou inválida nesta resposta; utilizada a conversão 6 − atendimento.'
                    : 'Conversão habitual 6 − atendimento; sem correspondência múltipla com notas válidas no recorte mensal.';
            } else {
                $score = null;
                $source = 'none';
                $reason = self::hasResponse($call) ? 'Nenhum dos campos contém nota inteira válida de 1 a 5.' : 'Pesquisa não respondida.';
            }
            $call['satisfaction'] = $score;
            $call['satisfaction_source'] = $source;
            $call['satisfaction_reason'] = $reason;
        }
        unset($call);
        $explanation = $preferNota
            ? 'Neste recorte mensal, atendimento (' . implode(', ', $ambiguous) . ') corresponde a mais de uma nota válida. Prioridade para nota, já tratada; onde nota é ausente ou inválida, aplica-se 6 − atendimento.'
            : 'Neste recorte mensal, não foi identificada correspondência de um mesmo atendimento com notas válidas distintas. Mantida a conversão 6 − atendimento; nota válida recupera respostas com atendimento ausente ou inválido.';
        return ['calls' => $calls, 'policy' => $preferNota ? 'nota_preferred' : 'atendimento_standard',
            'ambiguous_atendimento' => $ambiguous,
            'explanation' => $explanation . ' A comparação usa as ligações das filas e do mês selecionados antes das exclusões de números de teste; as exclusões continuam aplicadas às contagens.'];
    }
}
