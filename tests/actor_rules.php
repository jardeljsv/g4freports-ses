<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freports\Ses\ActorEvidence;
function check($ok, $why) { if (!$ok) throw new RuntimeException($why); }
$input = ['n1_group_ids' => [55], 'n2_group_ids' => [56], 'n3_group_ids' => [57]];
$base = ['id' => 7, 'solved_at' => '2026-06-02 12:00:00', 'reopened' => true, 'refused_solution' => true, 'group_ids' => [], 'ins6_eligible' => true];
$sols = [['ticket_id' => 7, 'solution_id' => 101, 'solution_at' => '2026-06-01 12:00:00', 'solver_id' => 123], ['ticket_id' => 7, 'solution_id' => 102, 'solution_at' => '2026-06-02 12:00:00', 'solver_id' => 123]];
$hist = [
    ['ticket_id' => 7, 'log_id' => 1, 'occurred_at' => '2026-06-01 12:00:00', 'actor_raw' => 'Agent (123)', 'from_status' => 2, 'to_status' => 5],
    ['ticket_id' => 7, 'log_id' => 2, 'occurred_at' => '2026-06-01 12:30:00', 'actor_raw' => 'End user (456)', 'from_status' => 5, 'to_status' => 6],
    ['ticket_id' => 7, 'log_id' => 3, 'occurred_at' => '2026-06-01 13:00:00', 'actor_raw' => 'Agent (123)', 'from_status' => 6, 'to_status' => 2],
    ['ticket_id' => 7, 'log_id' => 4, 'occurred_at' => '2026-06-02 12:00:00', 'actor_raw' => 'Agent (123)', 'from_status' => 2, 'to_status' => 5],
];
$members = [['user_id' => 123, 'user_name' => 'Agent', 'group_id' => 55], ['user_id' => 456, 'user_name' => 'End user', 'group_id' => 0]];
function evaluated($base, $hist, $sols, $members, $input, $manual = false) {
    $r = ActorEvidence::annotate([$base], $hist, $sols, $members);
    return ActorEvidence::classify($r[0], $input, $manual);
}
$t = evaluated($base, $hist, $sols, $members, $input);
check(!$t['ins5'] && $t['ins5_excluded_administrative'] && $t['ins5_exempt_event_count'] === 1, 'Same preceding solver is exempt even after end-user closure/refused solution');
check($t['ins6'] && $t['ins6_solver_fallback'] && $t['ins6_basis'] === 'solver_n1_without_group', 'Actual N1 solver accepts eligible no-group case');
check($t['ins5_reopening_events'][0]['solution_id'] === 101, 'Reopen must use preceding solution, not final solution');
$t = evaluated($base, $hist, $sols, $members, $input, true);
check($t['ins5'] && !$t['ins5_excluded_administrative'], 'Manual incomplete execution overrides exemption');
$more = $hist;
$more[] = ['ticket_id' => 7, 'log_id' => 5, 'occurred_at' => '2026-06-02 13:00:00', 'actor_raw' => 'Other agent (124)', 'from_status' => 5, 'to_status' => 2];
$t = evaluated($base, $more, $sols, $members, $input);
check($t['ins5'] && $t['ins5_counted_reopening_count'] === 1 && $t['ins5_exempt_event_count'] === 1, 'Any non-exempt reopen keeps ticket counted once');
$anonymous = $hist; $anonymous[2]['actor_raw'] = 'Agent';
$t = evaluated($base, $anonymous, $sols, $members, $input);
check($t['ins5'], 'Matching name without exact ID cannot exempt');
$other = $base; $other['group_ids'] = [56];
$t = evaluated($other, $hist, $sols, $members, $input);
check(!$t['ins6'] && !$t['ins6_solver_fallback'] && $t['ins6_basis'] === 'other_groups', 'Assigned N2 prevents missing-group fallback');
$other['group_ids'] = [55]; $nonN1 = [['user_id' => 123, 'user_name' => 'Agent', 'group_id' => 56]];
$t = evaluated($other, $hist, $sols, $nonN1, $input);
check($t['ins6'] && $t['ins6_basis'] === 'assigned_n1', 'Existing N1 branch is preserved even if solver not current N1');
$other['group_ids'] = [55,57]; $other['ins6_eligible'] = false;
$t = evaluated($other, $hist, $sols, $members, $input);
check(!$t['ins6'] && $t['ins6_basis'] === 'ineligible', 'N3 denominator exclusion wins');
$legacy = ActorEvidence::classify($base, $input, false);
check($legacy['ins5'], 'Missing historical evidence cannot silently remove a known reopening');
$conflicting = $sols; $conflicting[0]['solver_id'] = 456;
$t = evaluated($base, $hist, $conflicting, $members, $input);
check($t['ins5'] && $t['ins5_reopening_events'][0]['actor_conflict'], 'Conflicting persisted actor/status does not claim same solver');
// Future solution records cannot identify the solver for the selected solved cycle.
$futureSolutions = $sols;
$futureSolutions[] = ['ticket_id' => 7, 'solution_id' => 999, 'solution_at' => '2026-07-01 12:00:00', 'solver_id' => 456];
$futureHistory = $hist;
$futureHistory[] = ['ticket_id' => 7, 'log_id' => 999, 'occurred_at' => '2026-07-01 12:00:00', 'actor_raw' => 'End user (456)', 'from_status' => 2, 'to_status' => 5];
$t = evaluated($base, $futureHistory, $futureSolutions, $members, $input);
check($t['solver_id'] === 123 && $t['solution_id'] === 102 && $t['ins6'], 'Solver must stop at ticket solvedate; later records cannot change INS6');
check($t['ins5_reopening_events'][0]['solver_id'] === 123 && $t['ins5_reopening_events'][0]['solution_id'] === 101, 'Future solution cannot affect preceding reopening cycle');
// A later solved-status actor belongs to its own cycle, never to the prior persisted solution.
$statusOnly = $hist;
$statusOnly[] = ['ticket_id' => 7, 'log_id' => 5, 'occurred_at' => '2026-06-03 12:00:00', 'actor_raw' => 'Other agent (124)', 'from_status' => 2, 'to_status' => 5];
$statusOnly[] = ['ticket_id' => 7, 'log_id' => 6, 'occurred_at' => '2026-06-03 13:00:00', 'actor_raw' => 'Agent (123)', 'from_status' => 5, 'to_status' => 2];
$t = evaluated($base, $statusOnly, $sols, $members, $input);
check($t['ins5'] && $t['ins5_reopening_events'][1]['solver_id'] === 124, 'New status-only solution cycle must not reuse earlier solver');
check($t['ins5_reopening_events'][1]['solution_id'] === 0 && $t['ins5_reopening_events'][1]['solution_at'] === '2026-06-03 12:00:00', 'Audit must not attribute an older persisted solution ID to a new status-only cycle');
// Missing exact actor ID is not repaired by copying the reopening actor's name.
$statusOnly[4]['actor_raw'] = 'Other agent';
$t = evaluated($base, $statusOnly, $sols, $members, $input);
check($t['ins5'] && $t['ins5_reopening_events'][1]['solver_id'] === 0, 'Unknown actor in newer status cycle cannot reuse a stale solution author');
echo "PASS actor rules: exemptions, refusal, cycles, manual override, multi-event, exact IDs, fallback, group preservation and N3.\n";
