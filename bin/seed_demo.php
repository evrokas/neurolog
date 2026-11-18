#!/usr/bin/env php
<?php
/**
 * Development only: made-up hospitals and group members, so the operation form has something to pick.
 *   php bin/seed_demo.php [--link-surgeon=UNAME]
 * Idempotent (matched by name). Never run on a real installation: the names are invented.
 */
require_once(__DIR__ . '/lib/cli_boot.php');
$opts = getopt('', ['link-surgeon:']);
$db = dbConnection::getConnection();
$hosp = ['Demo General Hospital', 'Demo Spine Clinic'];
$mem = [['Demo Surgeon A', 'surgeon'], ['Demo Surgeon B', 'surgeon'], ['Demo Anaesthetist', 'anaesthetist'], ['Demo Resident', 'resident']];
foreach ($hosp as $h) {
    $st = $db->prepare('SELECT id FROM hospitals WHERE name = ?'); $st->execute([$h]);
    if (!$st->fetchColumn()) { $db->prepare('INSERT INTO hospitals (name, active, cuser) VALUES (?, 1, ?)')->execute([$h, 'seed']); echo "hospital $h\n"; }
}
foreach ($mem as $k => [$n, $kind]) {
    $st = $db->prepare('SELECT id FROM members WHERE name = ?'); $st->execute([$n]);
    if (!$st->fetchColumn()) {
        $u = $k === 0 ? ($opts['link-surgeon'] ?? null) : null;
        $db->prepare('INSERT INTO members (name, kind, uname, active, cuser) VALUES (?, ?, ?, 1, ?)')->execute([$n, $kind, $u, 'seed']);
        echo "member $n ($kind)" . ($u ? " linked to $u" : '') . "\n";
    }
}
