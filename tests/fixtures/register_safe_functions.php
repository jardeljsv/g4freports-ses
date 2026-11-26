<?php
namespace Safe;

// GLPI 11 imports the Safe wrapper in DBmysql::quoteName(). The isolated
// transport fixture has no GLPI vendor tree; preserve that wrapper's behavior.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists(__NAMESPACE__ . '\\preg_match')) {
    function preg_match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int
    {
        $result = \preg_match($pattern, $subject, $matches, $flags, $offset);
        if ($result === false) throw new \RuntimeException('Fixture regular expression failed.');
        return $result;
    }
}
