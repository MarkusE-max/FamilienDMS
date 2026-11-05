<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

require_login();
$body = '<section class="page-intro"><p class="eyebrow">Dein FamilienDMS</p><h1>Gestaltung</h1><p>Wähle eine Akzentfarbe und Schriftart für deine Ansicht. Die Vorschau erscheint sofort.</p></section>';
$body .= '<section class="panel settings-panel"><h2>Farben und Schrift</h2><p class="panel-subtitle">Diese Einstellung wird für dein Benutzerkonto in diesem Browser gespeichert.</p><div class="settings-controls"><label for="preference-green">Grünton <input id="preference-green" type="color" value="#315e4a" aria-describedby="green-hint"></label><p class="form-hint" id="green-hint">Die Farbe wird auch für Schaltflächen und Hervorhebungen verwendet.</p><label for="preference-font">Schriftart <select id="preference-font"><option value="system">Systemschrift</option><option value="arial">Arial</option><option value="verdana">Verdana</option><option value="georgia">Georgia</option></select></label></div><div class="settings-preview" aria-live="polite"><span class="settings-preview-mark" aria-hidden="true">F</span><div><strong>So sieht es aus</strong><p>Deine Dokumente bleiben gut lesbar und wiedererkennbar.</p><button type="button">Beispiel-Schaltfläche</button></div></div><div class="settings-actions"><button class="button-secondary" id="preference-reset" type="button">Standard wiederherstellen</button><span class="form-hint" id="preference-status" role="status">Änderungen werden automatisch gespeichert.</span></div></section>';
page('Gestaltung', $body);
