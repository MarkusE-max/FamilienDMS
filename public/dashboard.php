<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();
$count = (int) db()->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$body = '<section class="dashboard-heading"><div><p class="eyebrow">Dein Familienarchiv</p><h1>Guten Tag, ' . h($user['display_name']) . '</h1><div class="page-intro"><p>Willkommen in eurem geschützten Bereich. Hier wächst Schritt für Schritt die gemeinsame Ablage wichtiger Unterlagen.</p></div></div><span class="date-note">Privater Bereich · v0.1 Alpha</span></section>';
$body .= '<section class="dashboard-grid" aria-label="Übersicht">';
$body .= '<article class="stat-card"><span class="stat-mark" aria-hidden="true">♙</span><span class="stat-label">Aktive Konten</span><strong class="stat-value">' . $count . '</strong><span class="stat-caption">Familienmitglieder mit Zugang</span></article>';
$body .= '<article class="stat-card"><span class="stat-mark" aria-hidden="true">▤</span><span class="stat-label">Dokumente</span><strong class="stat-value">—</strong><span class="stat-caption">Archivbereich in Vorbereitung</span></article>';
$body .= '<article class="stat-card"><span class="stat-mark" aria-hidden="true">✓</span><span class="stat-label">Sicherheit</span><strong class="stat-value" style="font-size:21px;margin-top:17px">Geschützter Zugang</strong><span class="stat-caption">Anmeldung erforderlich</span></article>';
$body .= '<article class="panel archive-panel"><div class="panel-heading"><div><p class="eyebrow">Euer gemeinsamer Ort</p><h2>Dokumentenarchiv</h2><p>Der nächste Ausbau bringt Ordner, Dokumente und eine übersichtliche Suche an diesen Platz.</p></div><span class="pill">In Vorbereitung</span></div><div class="archive-illustration" aria-hidden="true"><span>▤</span><span>▤</span><span>▤</span><span>Ein ruhiger Platz für wichtige Unterlagen</span></div></article>';
$body .= '<aside class="panel setup-panel"><p class="eyebrow">Guter Anfang</p><h2>Einrichtung</h2><p>Diese Schritte helfen, die Alpha-Version für eure Familie vorzubereiten.</p><ul class="setup-list"><li><span class="setup-check">✓</span><span>Administratorzugang wurde erstellt.</span></li><li><span class="setup-check">' . ($user['role'] === 'admin' ? '→' : '✓') . '</span><span>' . ($user['role'] === 'admin' ? '<a href="' . h(app_url('users.php')) . '">Weitere Familienkonten einrichten</a>' : 'Dein Familienkonto ist bereit.') . '</span></li><li><span class="setup-check">!</span><span>Installationsseite nach der Einrichtung sperren.</span></li></ul></aside>';
$body .= '</section>';
page('Übersicht', $body);
