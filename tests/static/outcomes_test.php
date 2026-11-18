<?php
// web/outcomes.php (M4): complications, follow-up windows, scores asked and checked, the due list. No DB. Exit 0 = pass.
$root = dirname(__DIR__, 2);
if (!function_exists('yaml_parse_file')) require_once("$root/fw/core/lib/yaml_compat.php");
require "$root/web/nl_id.php"; require "$root/web/vocab.php"; require "$root/web/operations.php"; require "$root/web/outcomes.php";
$lists = nl_lists(null, nl_vocab_rows($root));
$cfg = nl_followup_cfg($root);
$fail = 0; $n = 0;
function t(string $l, bool $ok, $got = null): void { global $fail, $n; $n++; if (!$ok) { $fail++; echo "FAIL $l" . ($got !== null ? '  ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; } }

// ---------- complications ----------
$op = ['id' => 1, 'op_date' => '2026-03-01', 'patient_ref' => 10];
[$A, $B, $C] = [nl_id_new('O'), nl_id_new('O'), nl_id_new('O')];
$ops = [$A => ['id' => 2, 'op_date' => '2026-03-05', 'patient_ref' => 10], $B => ['id' => 3, 'op_date' => '2026-03-05', 'patient_ref' => 11],
        $C => ['id' => 4, 'op_date' => '2026-02-01', 'patient_ref' => 10]];
$ctx = ['lists' => $lists, 'ops' => $ops];
$base = ['complication_type' => 'csf_leak', 'clavien_dindo' => 'IIIb', 'treatment' => 'reoperation'];
$v = nl_complication_validate($base + ['onset_date' => '2026-03-04', 'reoperation_op' => nl_id_format($A)], $op, $ctx, '2026-06-01');
t('a CSF leak on day 3, reoperated: kept, timing derived, reoperation linked', $v['ok'] && $v['row']['timing'] === '48h_30d' && $v['row']['reoperation_op_ref'] === 2, $v);
$v = nl_complication_validate($base + ['onset_date' => '2026-07-01'], $op, $ctx, '2026-08-01');
t('after 90 days: refused (a diagnosis of the later operation)', isset($v['errors']['onset_date']), $v['errors']);
$v = nl_complication_validate($base + ['onset_date' => '2026-03-20', 'timing' => 'lt_48h'], $op, $ctx, '2026-06-01');
t('a timing that does not fit the onset date is refused', isset($v['errors']['timing']), $v['errors']);
$v = nl_complication_validate($base + ['onset_date' => '2026-03-01', 'timing' => 'intraoperative'], $op, $ctx, '2026-06-01');
t('intraoperative on the day of surgery is accepted', $v['ok'], $v['errors']);
$v = nl_complication_validate($base + ['reoperation_op' => $B], $op, $ctx, '2026-06-01');
t('a reoperation of another patient is refused', isset($v['errors']['reoperation_op']), $v['errors']);
$v = nl_complication_validate($base + ['reoperation_op' => $C], $op, $ctx, '2026-06-01');
t('a reoperation dated before the operation is refused', isset($v['errors']['reoperation_op']), $v['errors']);
$v = nl_complication_validate(['complication_type' => 'csf_leak', 'clavien_dindo' => 'II', 'treatment' => 'medication', 'outcome_at_90d' => 'died'], $op, $ctx, '2026-06-01');
t('death needs grade V', isset($v['errors']['clavien_dindo']), $v['errors']);
$v = nl_complication_validate($base, $op, $ctx, '2026-06-01');
t('reoperation without the operation ID: saved, listed as missing', $v['ok'] && in_array('the reoperation (its operation ID)', $v['missing'], true), $v['missing']);
$v = nl_complication_validate([], $op, $ctx, '2026-06-01');
t('type, grade and treatment are required', isset($v['errors']['complication_type'], $v['errors']['clavien_dindo'], $v['errors']['treatment']), $v['errors']);

// ---------- windows ----------
t('a fusion has the 24-month window', nl_followup_windows(['construct' => 'cage_and_screws'], $cfg) === ['6w', '3m', '12m', '24m']);
t('a tumour has it too', in_array('24m', nl_followup_windows(['classes' => ['neoplastic']], $cfg), true));
t('a discectomy has 6w, 3m, 12m', nl_followup_windows(['construct' => 'none', 'actions' => ['discectomy'], 'classes' => ['degenerative']], $cfg) === ['6w', '3m', '12m']);
t('day 90 is the 3m window', nl_window_for('2026-01-01', '2026-04-01', ['6w', '3m', '12m'], $cfg) === '3m');
t('day 200 is unscheduled', nl_window_for('2026-01-01', '2026-07-20', ['6w', '3m', '12m'], $cfg) === 'unscheduled');

// ---------- scores asked ----------
$lumbar = ['procedures' => [['procedure_code' => 'discectomy', 'domain' => 'spinal', 'region' => 'lumbosacral']], 'diagnoses' => [['diagnosis_code' => 'disc_herniation', 'pathology_class' => 'degenerative']]];
t('lumbar: VAS back, VAS leg, ODI, EQ-5D', nl_scores_asked($lumbar, $cfg) === ['vas_back', 'vas_leg', 'odi', 'eq5d'], nl_scores_asked($lumbar, $cfg));
$cerv = ['procedures' => [['procedure_code' => 'acdf', 'domain' => 'spinal', 'region' => 'cervical']], 'diagnoses' => [['diagnosis_code' => 'myelopathy', 'pathology_class' => 'degenerative']]];
t('cervical myelopathy adds mJOA', nl_scores_asked($cerv, $cfg) === ['vas_neck', 'vas_arm', 'ndi', 'mjoa', 'eq5d'], nl_scores_asked($cerv, $cfg));
$tn = ['procedures' => [['procedure_code' => 'trigeminal_percutaneous', 'domain' => 'functional_pain']], 'diagnoses' => [['diagnosis_code' => 'trigeminal_neuralgia', 'pathology_class' => 'pain']]];
t('trigeminal: BNI pain and numbness', array_values(array_intersect(nl_scores_asked($tn, $cfg), ['bni_pain', 'bni_numbness'])) === ['bni_pain', 'bni_numbness'], nl_scores_asked($tn, $cfg));
t('nothing matched: EQ-5D', nl_scores_asked([], $cfg) === ['eq5d']);

[$rows, $err, $miss] = nl_scores_validate(['vas_back' => ['value' => '3'], 'odi' => ['value' => '22,5'], 'eq5d' => ['reason' => 'declined'], 'bni_pain' => ['value' => 'IIIb']],
    ['vas_back', 'vas_leg', 'odi', 'eq5d'], $lists, $cfg);
t('scores: an integer score with a decimal is refused', isset($err['sc.odi.value']), $err);
t('scores: a reason instead of a value is kept; a missing asked score is listed', isset($rows['eq5d']) && $rows['eq5d']['not_done_reason'] === 'declined' && in_array('VAS leg', $miss, true), [$rows, $miss]);
t('scores: a graded score keeps its code', ($rows['bni_pain']['value_code'] ?? '') === 'IIIb');
[, $err] = nl_scores_validate(['kps' => ['value' => '75'], 'vas_leg' => ['value' => '11'], 'mrs' => ['value' => '2', 'reason' => 'x']], [], $lists, $cfg);
t('KPS in steps of 10; VAS 0–10; value and reason not both', isset($err['sc.kps.value'], $err['sc.vas_leg.value'], $err['sc.mrs.reason']), $err);

// ---------- follow-up ----------
$case = ['op_date' => '2026-01-01', 'windows' => ['6w', '3m', '12m'], 'taken' => ['6w'], 'fusion' => false, 'tumour' => false, 'epilepsy' => false];
$v = nl_followup_validate(['status' => 'seen', 'visit_date' => '2026-04-01', 'fu_window' => '6w', 'neuro_status' => 'improved', 'no_complications' => '1'], $case, $lists, '2026-05-01', $cfg);
t('the window follows from the date (day 90 → 3m), whatever was chosen', $v['ok'] && $v['row']['fu_window'] === '3m', $v);
$v = nl_followup_validate(['status' => 'seen', 'visit_date' => '2026-02-15'], $case, $lists, '2026-05-01', $cfg);
t('a second 6w follow-up is refused', isset($v['errors']['fu_window']), $v['errors']);
$v = nl_followup_validate(['status' => 'lost', 'fu_window' => '12m'], $case, $lists, '2027-05-01', $cfg);
t('lost to follow-up without a date keeps the chosen window', $v['ok'] && $v['row']['fu_window'] === '12m', $v);
$v = nl_followup_validate(['status' => 'seen', 'visit_date' => '2026-04-01'], $case, $lists, '2026-05-01', $cfg);
t('seen: "no complication" must be stated', in_array('complications since the operation (none, or recorded)', $v['missing'], true), $v['missing']);
$v = nl_followup_validate(['status' => 'seen', 'visit_date' => '2026-04-01', 'date_of_death' => '2026-03-01'], $case, $lists, '2026-05-01', $cfg);
t('a date of death needs status died', isset($v['errors']['status']), $v['errors']);
$v = nl_followup_validate(['status' => 'seen', 'visit_date' => '2026-09-01'], $case, $lists, '2026-05-01', $cfg);
t('a visit in the future is refused', isset($v['errors']['visit_date']));

// ---------- due list ----------
$d = nl_followups_due('2026-01-01', ['6w', '3m', '12m'], ['6w'], false, '2026-03-05', $cfg);
t('day 63 after a done 6w: 3m upcoming (shown 14 days early)', count($d) === 1 && $d[0]['window'] === '3m' && $d[0]['state'] === 'upcoming', $d);
$d = nl_followups_due('2026-01-01', ['6w', '3m', '12m'], [], false, '2026-06-01', $cfg);
t('day 151: 6w and 3m overdue, 12m not yet shown', array_column($d, 'state', 'window') === ['6w' => 'overdue', '3m' => 'overdue'], $d);
t('after death nothing is due', nl_followups_due('2026-01-01', ['6w'], [], true, '2026-06-01', $cfg) === []);

echo ($n - $fail) . "/$n passed\n";
exit($fail ? 1 : 0);
