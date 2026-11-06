<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();
$count = (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$documentCount = (int) db()->query('SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL')->fetchColumn();
$pendingResetCount = $user['role'] === 'admin' ? (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 1 AND password_reset_requested_at IS NOT NULL')->fetchColumn() : 0;
$body = '<section class="dashboard-heading"><div><p class="eyebrow">Dein Familienarchiv</p><h1>Guten Tag, ' . h($user['display_name']) . '</h1><div class="page-intro"><p>Willkommen in eurem geschützten Bereich. Hier wächst Schritt für Schritt die gemeinsame Ablage wichtiger Unterlagen.</p></div></div><span class="date-note">Privater Bereich · v0.1 Alpha</span></section>';
if ($pendingResetCount > 0) {
    $label = $pendingResetCount === 1 ? 'Ein Benutzer hat eine Passwortrücksetzung angefragt.' : $pendingResetCount . ' Benutzer haben eine Passwortrücksetzung angefragt.';
    $body .= '<div class="alert-warning reset-request-alert" role="status"><span aria-hidden="true">!</span><span>' . h($label) . ' <a href="' . h(app_url('users.php')) . '">Zur Benutzerverwaltung</a></span></div>';
}
$body .= '<section class="dashboard-grid" aria-label="Übersicht">';
$body .= '<article class="stat-card"><span class="stat-mark" aria-hidden="true">♙</span><span class="stat-label">Aktive Konten</span><strong class="stat-value">' . $count . '</strong><span class="stat-caption">Familienmitglieder mit Zugang</span></article>';
$body .= '<article class="stat-card"><span class="stat-mark" aria-hidden="true">▤</span><span class="stat-label"><a href="' . h(app_url('documents.php')) . '">Dokumente</a></span><strong class="stat-value">' . $documentCount . '</strong><span class="stat-caption">Im Familienarchiv gespeichert</span></article>';
$body .= '<article class="stat-card"><span class="stat-mark" aria-hidden="true">✓</span><span class="stat-label">Sicherheit</span><strong class="stat-value" style="font-size:21px;margin-top:17px">Geschützter Zugang</strong><span class="stat-caption">Anmeldung erforderlich</span></article>';
$body .= '<article class="panel archive-panel"><div class="panel-heading"><div><p class="eyebrow">Euer gemeinsamer Ort</p><h2>Dokumentenarchiv</h2><p>Lade wichtige Unterlagen hoch, finde sie über die Suche wieder und lade sie bei Bedarf sicher herunter.</p><a class="button archive-action" href="' . h(app_url('documents.php')) . '">Zum Archiv <span aria-hidden="true">→</span></a></div><span class="pill">Bereit</span></div><div class="archive-illustration" aria-hidden="true"><span>▤</span><span>▤</span><span>▤</span><span>Privat gespeichert, nur angemeldet erreichbar</span></div></article>';
$body .= '<aside class="panel setup-panel"><p class="eyebrow">Guter Anfang</p><h2>Einrichtung</h2><p>Die wichtigsten Bereiche für eure Familie.</p><ul class="setup-list"><li><span class="setup-check">✓</span><span>Administratorzugang wurde erstellt.</span></li><li><span class="setup-check">' . ($user['role'] === 'admin' ? '→' : '✓') . '</span><span>' . ($user['role'] === 'admin' ? '<a href="' . h(app_url('users.php')) . '">Familienkonten verwalten</a>' : 'Dein Familienkonto ist bereit.') . '</span></li><li><span class="setup-check">→</span><span><a href="' . h(app_url('documents.php')) . '">Erstes Dokument ablegen</a></span></li></ul></aside>';
$body .= '</section>';
page('Übersicht', $body);
