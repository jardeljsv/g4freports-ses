<?php

namespace GlpiPlugin\G4freports\Ses;

/** Durable contractual baseline and explicitly entered monthly financial decisions. */
final class FinanceSettings
{
    public const CONFIG_KEY = 'ses_financial_json';

    public static function fromSettings(array $settings): array
    {
        $config = self::normalize($settings[self::CONFIG_KEY] ?? '{}');
        // Existing saved monthly decisions survive first use of the contractual reference.
        // A non-empty custom reference is never replaced by a plugin update.
        if (!$config['contract_fronts'] && !array_key_exists('contract_total', $config)) {
            $baseline = self::defaultBaseline();
            $config['contract_fronts'] = $baseline['contract_fronts'];
            $config['contract_total'] = $baseline['contract_total'];
        }
        return $config;
    }

    /** SES/DF service order 005/2026, with the contractual total approved by the customer. */
    public static function defaultBaseline(): array
    {
        $line = static function (string $profile, int $quantity, string $rate): array {
            return ['profile' => $profile, 'contract_quantity' => $quantity, 'monthly_rate' => $rate];
        };
        return self::normalize(['contract_total' => '562457.91', 'months' => [], 'contract_fronts' => [
            ['id' => 'gestao', 'label' => 'Gerenciamento de Atendimento a Usuários de TIC', 'profile_lines' => [
                $line('Gerente de suporte técnico de tecnologia da informação', 1, '14909.87'),
                $line('Desenvolvedor de sistemas de tecnologia da informação', 1, '14013.54'),
            ]],
            ['id' => 'n1', 'label' => 'Atendimento a Usuários de TIC - 1º Nível', 'profile_lines' => [
                $line('Técnico de suporte ao usuário de tecnologia da informação Pleno', 10, '4727.49'),
                $line('Técnico de suporte ao usuário de tecnologia da informação Sênior', 10, '5980.99'),
            ]],
            ['id' => 'n2', 'label' => 'Atendimento a Usuários de TIC - 2º Nível', 'profile_lines' => [
                $line('Técnico em manutenção de equipamentos de informática Pleno', 22, '4428.82'),
                $line('Técnico em manutenção de equipamentos de informática Sênior', 23, '5433.02'),
                $line('Analista de suporte computacional Pleno', 3, '9182.02'),
                $line('Analista de suporte computacional Sênior', 1, '14053.37'),
            ]],
            ['id' => 'aplicacoes', 'label' => 'Sustentação de Infraestrutura para Aplicações', 'profile_lines' => [
                $line('Administrador de sistemas operacionais Sênior', 2, '16457.73'),
            ]],
            ['id' => 'conectividade', 'label' => 'Sustentação de Infraestrutura para Conectividade e Comunicação', 'profile_lines' => [
                $line('Técnico de Rede (Telecomunicações) Sênior', 4, '6757.55'),
                $line('Analista de redes e de comunicação de dados Sênior', 2, '18364.59'),
            ]],
            ['id' => 'seguranca', 'label' => 'Sustentação de Infraestrutura para Segurança de TIC', 'profile_lines' => [
                $line('Administrador em segurança da informação Sênior', 2, '21273.31'),
            ]],
            ['id' => 'monitoramento', 'label' => 'Sustentação de Infraestrutura para Monitoramento de Serviços de TIC', 'profile_lines' => [
                $line('Analista de suporte computacional Pleno', 1, '9182.02'),
                $line('Analista de suporte computacional Sênior', 1, '14053.37'),
            ]],
        ]]);
    }

    public static function normalize($raw): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > 60000 || substr(ltrim($raw), 0, 1) !== '{') {
                throw new \InvalidArgumentException('A configuração financeira deve ser um objeto JSON de até 60.000 bytes.');
            }
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException('Configuração financeira inválida.');
        }
        if (!is_array($raw)) throw new \InvalidArgumentException('Configuração financeira inválida.');
        $fronts = $raw['contract_fronts'] ?? [];
        $months = $raw['months'] ?? [];
        if (!is_array($fronts) || !self::isList($fronts) || count($fronts) > 50 || !is_array($months) || count($months) > 240) {
            throw new \InvalidArgumentException('Informe até 50 frentes e 240 competências na configuração financeira.');
        }
        $result = ['contract_fronts' => [], 'months' => []];
        if (array_key_exists('contract_total', $raw)) {
            $result['contract_total'] = $raw['contract_total'] === null || $raw['contract_total'] === ''
                ? null : self::money($raw['contract_total'], false);
        }
        $ids = [];
        foreach ($fronts as $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('Frente contratual inválida.');
            $id = self::text($row['id'] ?? '', 64, 'Identificador da frente');
            if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id) || isset($ids[$id])) throw new \InvalidArgumentException('Identificador da frente inválido ou duplicado.');
            $ids[$id] = true;
            $lines = $row['profile_lines'] ?? [];
            if (!is_array($lines) || !self::isList($lines) || count($lines) > 100) throw new \InvalidArgumentException('Perfis contratuais da frente inválidos.');
            $normalizedLines = $lineKeys = [];
            $lineQuantity = 0;
            foreach ($lines as $line) {
                if (!is_array($line)) throw new \InvalidArgumentException('Perfil contratual inválido.');
                $profile = self::text($line['profile'] ?? '', 300, 'Perfil profissional');
                $key = self::profileKey($profile);
                if (isset($lineKeys[$key])) throw new \InvalidArgumentException('O perfil ' . $profile . ' está repetido na mesma frente.');
                $lineKeys[$key] = true;
                $quantity = $line['contract_quantity'] ?? 0;
                if (!self::integer($quantity) || (int)$quantity > 10000) throw new \InvalidArgumentException('Quantidade contratada inválida.');
                $lineQuantity += (int)$quantity;
                if ($lineQuantity > 10000) throw new \InvalidArgumentException('Quantidade contratada inválida.');
                $normalizedLines[] = ['profile' => $profile, 'contract_quantity' => (int)$quantity,
                    'monthly_rate' => self::money($line['monthly_rate'] ?? '0', false)];
            }
            $quantity = $normalizedLines ? $lineQuantity : ($row['contract_quantity'] ?? 0);
            if (!self::integer($quantity) || (int)$quantity > 10000) throw new \InvalidArgumentException('Quantidade contratada inválida.');
            $mapped = $row['profiles'] ?? [];
            if (!is_array($mapped) || !self::isList($mapped) || count($mapped) > 100) throw new \InvalidArgumentException('Perfis da frente inválidos.');
            // A shared profile is legitimate across service fronts (N2 and monitoring).
            // resolveFront requires an explicit assignment when that match is ambiguous.
            $list = $profileKeys = [];
            foreach ($normalizedLines as $line) $mapped[] = $line['profile'];
            foreach ($mapped as $profile) {
                $profile = self::text($profile, 300, 'Perfil profissional');
                $key = self::profileKey($profile);
                if (isset($profileKeys[$key])) continue;
                $profileKeys[$key] = true;
                $list[] = $profile;
            }
            if (count($list) > 100) throw new \InvalidArgumentException('Perfis da frente inválidos.');
            $front = ['id' => $id, 'label' => self::text($row['label'] ?? '', 240, 'Frente de serviço'),
                'profiles' => $list, 'contract_quantity' => (int)$quantity,
                'monthly_rate' => $normalizedLines ? '0.00' : self::money($row['monthly_rate'] ?? '0', false)];
            if ($normalizedLines) $front['profile_lines'] = $normalizedLines;
            $result['contract_fronts'][] = $front;
        }
        foreach ($months as $month => $row) {
            if (!is_string($month) || !preg_match('/\A(20\d{2}|2100)-(0[1-9]|1[0-2])\z/D', $month) || !is_array($row)) {
                throw new \InvalidArgumentException('Competência financeira inválida.');
            }
            $overrides = $row['deduction_overrides'] ?? [];
            $adjustments = $row['adjustments'] ?? [];
            if (!is_array($overrides) || !is_array($adjustments) || !self::isList($adjustments) || count($adjustments) > 100) {
                throw new \InvalidArgumentException('Ajustes financeiros inválidos na competência ' . $month . '.');
            }
            $normalized = ['deduction_overrides' => [], 'adjustments' => []];
            foreach ($overrides as $id => $override) {
                if (!preg_match('/\AINS([1-9]|1[0-2])\z/D', (string)$id) || !is_array($override)) throw new \InvalidArgumentException('Indicador financeiro inválido.');
                $rate = $override['rate'] ?? null;
                if ($rate === '' || $rate === null) continue;
                if (!is_scalar($rate) || is_bool($rate) || !preg_match('/\A\d+(?:[.,]\d{1,2})?\z/D', (string)$rate)) {
                    throw new \InvalidArgumentException($id . ': informe uma glosa de 0 a 4%.');
                }
                $rate = (float)str_replace(',', '.', (string)$rate);
                if ($rate > 4) throw new \InvalidArgumentException($id . ': informe uma glosa de 0 a 4%.');
                $normalized['deduction_overrides'][$id] = ['rate' => $rate,
                    'justification' => self::text($override['justification'] ?? '', 2000, $id . ': justificativa do ajuste financeiro aprovado')];
            }
            ksort($normalized['deduction_overrides'], SORT_NATURAL);
            foreach ($adjustments as $adjustment) {
                if (!is_array($adjustment)) throw new \InvalidArgumentException('Ajuste financeiro inválido.');
                $normalized['adjustments'][] = ['description' => self::text($adjustment['description'] ?? '', 1000, 'Descrição do ajuste'),
                    'amount' => self::money($adjustment['amount'] ?? '', true)];
            }
            if ($normalized['deduction_overrides'] || $normalized['adjustments']) $result['months'][$month] = $normalized;
        }
        ksort($result['months']);
        if (strlen(self::json($result)) > 60000) throw new \InvalidArgumentException('A configuração financeira excede 60.000 bytes.');
        return $result;
    }

    public static function json(array $config): string
    {
        // Empty month/override collections are objects, also after a browser round trip.
        $config['months'] = (object)($config['months'] ?? []);
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function revision(array $config): string { return hash('sha256', self::json($config)); }

    public static function profileKey(string $profile): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $profile)), 'UTF-8');
    }

    /** Explicit assignment, otherwise only a unique profile match may select a front. */
    public static function resolveFront(array $person, array $fronts): string
    {
        $explicit = $person['service_front'] ?? '';
        $profileKey = self::profileKey((string)($person['profile'] ?? ''));
        $matches = [];
        foreach ($fronts as $front) {
            if ($explicit !== '' && $explicit === $front['id']) return $front['id'];
            if ($profileKey === '') continue;
            foreach ($front['profiles'] ?? [] as $profile) {
                if (self::profileKey($profile) === $profileKey) $matches[$front['id']] = true;
            }
        }
        if ($explicit !== '') return '__unmapped';
        return count($matches) === 1 ? (string)key($matches) : '__unmapped';
    }

    public static function cents($value): int
    {
        return (int)str_replace('.', '', self::money($value, true));
    }

    public static function amount(int $cents): string { return number_format($cents / 100, 2, '.', ''); }

    public static function money($value, bool $signed): string
    {
        if (!is_scalar($value) || is_bool($value)) throw new \InvalidArgumentException('Valor financeiro inválido.');
        $value = trim((string)$value);
        $value = preg_replace('/\AR\$\s*/u', '', $value);
        // Accept the same Brazilian formatting as the personnel register.
        if (strpos($value, ',') !== false) {
            if (!preg_match('/\A-?(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}\z/D', $value)) {
                throw new \InvalidArgumentException('Formato monetário inválido.');
            }
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        $pattern = $signed ? '/\A-?\d{1,9}(?:\.\d{1,2})?\z/D' : '/\A\d{1,9}(?:\.\d{1,2})?\z/D';
        if (!preg_match($pattern, $value)) throw new \InvalidArgumentException('Informe um valor financeiro com até duas casas decimais.');
        return number_format((float)$value, 2, '.', '');
    }

    private static function integer($value): bool
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && preg_match('/\A\d+\z/D', $value));
    }

    private static function isList(array $value): bool
    {
        return !$value || array_keys($value) === range(0, count($value) - 1);
    }

    private static function text($value, int $limit, string $label): string
    {
        if (!is_string($value) || mb_strlen(trim($value), 'UTF-8') > $limit || trim($value) === '') {
            throw new \InvalidArgumentException($label . ': preenchimento obrigatório, até ' . $limit . ' caracteres.');
        }
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));
    }
}
