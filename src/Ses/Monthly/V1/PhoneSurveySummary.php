<?php

namespace GlpiPlugin\G4freports\Ses\Monthly\V1;

/** One telephone response per call ID, after the saved test-number exclusions. */
final class PhoneSurveySummary
{
    public static function normalizedRating(array $call): ?int
    {
        return PhoneSurveyScore::rating($call);
    }

    public static function hasResponse(array $call): bool
    {
        return PhoneSurveyScore::hasResponse($call);
    }

    public static function sourceLabel(array $call): string
    {
        $source = $call['satisfaction_source'] ?? (array_key_exists('satisfaction_raw', $call) ? 'atendimento' : 'legacy');
        return ['nota' => 'nota (sem inversão)', 'atendimento' => 'atendimento (6 − valor)',
            'legacy' => 'Nota já convertida na evidência', 'none' => 'Sem nota válida'][$source] ?? 'Sem nota válida';
    }

    public static function scoreRuleText(): string
    {
        return 'Telefonia: normalmente aplica-se 6 − atendimento. Se um mesmo atendimento corresponder a notas válidas distintas no recorte mensal, usa-se nota sem inversão. Nota ausente ou inválida nesse modo usa 6 − atendimento; atendimento ausente ou inválido pode ser recuperado por nota válida. Ambos os campos exigem valores inteiros de 1 a 5.';
    }

    public static function build(array $calls, bool $available, string $followup = '', string $mode = 'glpi_and_phone', array $resolution = []): array
    {
        $included = $mode === 'glpi_and_phone';
        $unique = [];
        foreach ($calls as $call) {
            $id = (string)$call['id'];
            $key = (string)($call['call_key'] ?? ($id . '|' . $call['queue']));
            $call['call_key'] = $key;
            // All queue rows receive the same latest survey with either source field.
            // Select one stable row to anchor its evidence and daily count.
            $order = (string)($call['entered_at'] ?? '') . '|' . $key;
            if (isset($unique[$id]) && strcmp($unique[$id]['order'], $order) <= 0) continue;
            $unique[$id] = ['order' => $order, 'call' => $call];
        }
        $responses = $valid = $favorable = 0;
        $representativeKeys = $validKeys = $favorableKeys = [];
        foreach ($unique as $row) {
            $call = $row['call'];
            $key = $call['call_key'];
            $representativeKeys[] = $key;
            if (!$available) continue;
            if (!self::hasResponse($call)) continue;
            $responses++;
            $score = self::normalizedRating($call);
            if ($score === null) continue;
            $valid++;
            $validKeys[] = $key;
            if ($score >= 4) {
                $favorable++;
                $favorableKeys[] = $key;
            }
        }
        $status = !$available ? ($included ? 'Telefonia selecionada, mas indisponível: INS12 pendente' : 'Telefonia indisponível; modo somente GLPI')
            : ($included ? 'Telefonia incluída no INS12' : 'Telefonia excluída por seleção: somente GLPI');
        if ($available && $responses === 0) $status .= '; nenhuma resposta vinculada';
        $explanation = $included
            ? 'O INS12 soma as respostas favoráveis do GLPI e da telefonia e divide pela soma das respostas válidas das duas fontes. Cada resposta tem o mesmo peso; não se faz média dos percentuais das fontes. '
            : 'O INS12 usa somente as pesquisas dos chamados GLPI, conforme a opção escolhida. As respostas telefônicas abaixo são demonstradas para conferência e não participam da fração. ';
        $explanation .= self::scoreRuleText() . ' As notas consideradas 4 e 5 são favoráveis. No GLPI, as notas 4 e 5 já são favoráveis, sem inversão. '
            . 'Respostas ausentes ou fora da escala não entram na base de cálculo. A telefonia conta uma resposta por identificador único de ligação, após excluir números de teste, mesmo quando a ligação aparece em várias filas. '
            . 'A consulta mantém a resposta mais recente com atendimento ou nota não nulo por identificador, inclusive recebida após o mês; a data de referência é a primeira entrada nas filas selecionadas dentro do mês. '
            . 'O recorte cobre as ligações do período e filas selecionados, não todas as pesquisas da central. Uma resposta de chamado e uma resposta de ligação são registros distintos; não se presume que pertençam à mesma interação.';
        if ($resolution) $explanation .= ' ' . ($resolution['explanation'] ?? '');
        if (!$available) $explanation .= $included
            ? ' A fonte telefônica não pôde ser consultada: o resultado combinado fica pendente, sem substituir silenciosamente a seleção por somente GLPI.'
            : ' A fonte telefônica indisponível não impede o cálculo no modo somente GLPI.';
        return [
            'available' => $available, 'included' => $included, 'mode' => $mode,
            'unique_calls' => count($unique),
            'responses' => $available ? $responses : null,
            'valid_responses' => $available ? $valid : null,
            'favorable_responses' => $available ? $favorable : null,
            'invalid_responses' => $available ? $responses - $valid : null,
            'without_response' => $available ? count($unique) - $responses : null,
            'representative_call_keys' => $representativeKeys,
            'valid_call_keys' => $validKeys,
            'favorable_call_keys' => $favorableKeys,
            'status' => $status, 'explanation' => $explanation,
            'resolution' => $resolution,
            'actions' => self::actions($available, $followup, $mode),
        ];
    }

    public static function actions(bool $available, string $followup, string $mode = 'glpi_and_phone'): string
    {
        $included = $mode === 'glpi_and_phone';
        $text = $included
            ? 'Critério aplicado: GLPI e telefonia incluídos, com soma das respostas favoráveis e válidas, deduplicação da pesquisa por ligação e exclusão dos números de teste. '
            : 'Critério aplicado: somente GLPI; a pesquisa telefônica foi excluída por seleção, mantendo suas evidências disponíveis para conferência. ';
        if (!$available) $text .= 'Acompanhamento técnico: verificar acesso e estrutura da tabela tab_pesquisa na conexão de telefonia. ';
        $text .= 'Os valores originais de atendimento e nota, a fonte utilizada, a nota considerada, as contagens por fonte e a seleção aplicada ficam registrados no relatório.';
        return $text . (trim($followup) !== '' ? "\nAcompanhamento informado: " . trim($followup)
            : "\nAcompanhamento gerencial: não informado nesta extração.");
    }
}
