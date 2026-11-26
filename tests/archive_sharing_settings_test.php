<?php
require_once dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freports\Ses\ArchiveSharingSettings;

$checks = 0;
function sharingCheck(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
foreach (['', '  ', '[]', []] as $raw) {
    sharingCheck(ArchiveSharingSettings::normalizeGroups($raw) === '', 'Empty means sharing disabled');
    sharingCheck(ArchiveSharingSettings::groups($raw) === [], 'Empty never becomes an unrestricted ACL');
}
sharingCheck(ArchiveSharingSettings::normalizeGroups('[55,"12",55]') === '[12,55]', 'Stable unique numeric membership');
sharingCheck(ArchiveSharingSettings::groups('[2147483647]') === [2147483647], 'Maximum GLPI identifier preserved');
sharingCheck(count(ArchiveSharingSettings::groups(range(1, 100))) === 100, 'Bounded configured groups supported');
foreach ([null, false, true, 0, 55, '55', '55,56', '{}', '[0]', '[-55]', '[1.5]', '[true]', '[null]', '[[]]', '["55x"]', '["01"]', '[2147483648]', '[1e2]', '{"0":55}', ['group'=>55], range(1,101), str_repeat(' ',8193)] as $bad) {
    try { ArchiveSharingSettings::normalizeGroups($bad); sharingCheck(false, 'Invalid group data must fail closed'); }
    catch (InvalidArgumentException $e) { sharingCheck(true, 'Invalid group data rejected'); }
}
echo 'Archive sharing settings: ' . $checks . " checks passed\n";
