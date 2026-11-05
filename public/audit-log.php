<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_admin();

$perPage = 50;
$currentPage = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
$total = (int) db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$currentPage = min($currentPage, $pages);
$offset = ($currentPage - 1) * $perPage;
$q = db()->prepare('SELECT l.id, l.event_type, l.details_json, l.created_at,
        a.display_name AS actor_name, t.display_name AS target_name
    FROM audit_log l
    LEFT JOIN users a ON a.id = l.actor_user_id
    LEFT JOIN users t ON t.id = l.target_user_id
    ORDER BY l.created_at DESC, l.id DESC LIMIT ? OFFSET ?');
$q->bindValue(1, $perPage, PDO::PARAM_INT);
$q->bindValue(2, $offset, PDO::PARAM_INT);
$q->execute();
$entries = $q->fetchAll();
$labels = [
    'account_created' => 'Benutzerkonto erstellt',
    'password_reset_by_admin' => 'Passwort durch Admin zurückgesetzt',
    'role_changed' => 'Rolle geändert',
    'account_deactivated' => 'Konto deaktiviert',
    'account_reactivated' => 'Konto aktiviert',
    'password_changed' => 'Eigenes Passwort geändert',
    'password_reset_requested' => 'Passwortrücksetzung angefragt',
];
$body = '<section class="page-intro"><p class="eyebrow">Sicherheit &amp; Nachvollziehbarkeit</p><h1>Änderungsprotokoll</h1><p>Benutzerkonten und Zugriffsänderungen der letzten Zeit. Passwörter und Anmeldedaten werden nicht protokolliert.</p></section>';
$body .= '<section class="panel"><p class="panel-subtitle">' . $total . ' Einträge · neueste zuerst</p>';
if (!$entries) {
    $body .= '<div class="empty-state">Noch keine Änderungen protokolliert. Neue Aktionen erscheinen hier.</div>';
} else {
    $body .= '<div class="table-wrap"><table><thead><tr><th>Zeitpunkt</th><th>Aktion</th><th>Ausgeführt von</th><th>Betroffenes Konto</th><th>Details</th></tr></thead><tbody>';
    foreach ($entries as $entry) {
        $details = $entry['details_json'] ? json_decode((string) $entry['details_json'], true) : [];
        if (!is_array($details)) $details = [];
        $detailText = '';
        if (isset($details['from'], $details['to'])) {
            $roles = ['admin' => 'Administrator', 'user' => 'Benutzer'];
            $detailText = ($roles[$details['from']] ?? 'Unbekannt') . ' → ' . ($roles[$details['to']] ?? 'Unbekannt');
        } elseif (isset($details['role'])) {
            $detailText = $details['role'] === 'admin' ? 'Rolle: Administrator' : 'Rolle: Benutzer';
        }
        $date = date('d.m.Y H:i', strtotime((string) $entry['created_at']));
        $body .= '<tr><td>' . h($date) . '</td><td>' . h($labels[$entry['event_type']] ?? 'Benutzeraktion') . '</td><td>' . h((string) ($entry['actor_name'] ?: 'Gelöschtes Konto')) . '</td><td>' . h((string) ($entry['target_name'] ?: 'Gelöschtes Konto')) . '</td><td>' . h($detailText ?: '—') . '</td></tr>';
    }
    $body .= '</tbody></table></div>';
    if ($pages > 1) {
        $body .= '<nav class="pagination" aria-label="Protokollseiten">';
        if ($currentPage > 1) $body .= '<a class="button-secondary" href="' . h(app_url('audit-log.php?page=' . ($currentPage - 1))) . '">Zurück</a>';
        $body .= '<span>Seite ' . $currentPage . ' von ' . $pages . '</span>';
        if ($currentPage < $pages) $body .= '<a class="button-secondary" href="' . h(app_url('audit-log.php?page=' . ($currentPage + 1))) . '">Weiter</a>';
        $body .= '</nav>';
    }
}
$body .= '</section>';
page('Änderungsprotokoll', $body);
