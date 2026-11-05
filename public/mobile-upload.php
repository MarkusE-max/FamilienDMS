<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_login();
$uploaded = (string) ($_GET['uploaded'] ?? '') === '1';
$body = '<section class="page-intro"><p class="eyebrow">Unterwegs erfassen</p><h1>Foto hochladen</h1><p>Fotografiere ein Dokument oder wähle ein Bild aus deiner Fotomediathek. Es wird wie jeder andere Upload privat gespeichert.</p></section>' .
    ($uploaded ? '<p class="alert-success" role="status">Das Foto wurde hochgeladen und zur Texterkennung vorgemerkt.</p>' : '') .
    '<section class="panel mobile-upload"><form method="post" action="' . h(app_url('documents.php')) . '" enctype="multipart/form-data">' .
    '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">' .
    '<input type="hidden" name="action" value="upload"><input type="hidden" name="return_to" value="mobile">' .
    '<label>Titel des Dokuments<input name="title" required maxlength="200" placeholder="Zum Beispiel: Rechnung Stromanbieter"></label>' .
    '<label class="photo-picker">Foto aufnehmen oder auswählen<input type="file" name="document" accept="image/jpeg,image/png" capture="environment" required><span class="photo-picker-hint">JPG oder PNG · höchstens 20 MB</span></label>' .
    '<div class="mobile-upload-note"><strong>Tipp:</strong> Lege das Dokument möglichst flach und gut beleuchtet ab. Du kannst nach dem Upload weitere Fotos hinzufügen.</div>' .
    '<div class="form-actions"><button type="submit">Foto sicher hochladen</button></div></form></section>' .
    '<p class="mobile-upload-back"><a href="' . h(app_url('documents.php')) . '">Zum gesamten Dokumentenarchiv</a></p>';
page('Foto hochladen', $body);
