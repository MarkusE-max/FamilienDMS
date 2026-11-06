<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/document_storage.php';
$user = require_login();
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) { http_response_code(404); exit('Dokument nicht gefunden.'); }

$statement = db()->prepare('SELECT d.id, d.title, d.mime_type, d.storage_name, d.uploaded_by, COALESCE(t.rotation_degrees, 0) AS rotation_degrees FROM documents d LEFT JOIN document_text t ON t.document_id = d.id WHERE d.id = ? AND d.deleted_at IS NULL');
$statement->execute([$documentId]);
$document = $statement->fetch();
if (!$document || !in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)) { http_response_code(404); exit('Dokument nicht gefunden.'); }
if (document_storage_file(app_config(), (string) $document['storage_name']) === null) { http_response_code(404); exit('Dokument nicht verfügbar.'); }
$canSetRotation = $user['role'] === 'admin' || (int) $document['uploaded_by'] === (int) $user['id'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') !== 'save_rotation_and_reindex') { http_response_code(400); exit('Ungültige Anfrage.'); }
    $angle = filter_var($_POST['rotation_degrees'] ?? null, FILTER_VALIDATE_INT);
    if (!$canSetRotation) {
        http_response_code(403);
        $error = 'Du kannst nur eigene Dokumente für die Neuerkennung ausrichten.';
    } elseif ((!is_int($angle) || $angle < 0 || $angle > 359)) {
        $error = 'Bitte einen Winkel zwischen 0° und 359° auswählen.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status FROM document_text WHERE document_id = ? FOR UPDATE');
            $lock->execute([$documentId]);
            $textState = $lock->fetch();
            if (!$textState) {
                $pdo->rollBack();
                $error = 'Die OCR-Warteschlange für dieses Dokument wurde nicht gefunden.';
            } elseif ($textState['status'] === 'processing') {
                $pdo->rollBack();
                $error = 'Das Dokument wird gerade erkannt. Bitte warte, bis der OCR-Auftrag fertig ist.';
            } else {
                $pdo->prepare("UPDATE document_text SET status = 'pending', content = NULL, lease_until = NULL, error_code = NULL, indexed_at = NULL, force_ocr = 1, rotation_degrees = ?, error_detail = NULL WHERE document_id = ?")->execute([(int) $angle, $documentId]);
                record_audit_event((int) $user['id'], 'document_reindex_requested', (int) $document['uploaded_by'], ['document_id' => (int) $documentId, 'rotation_degrees' => (int) $angle]);
                $pdo->commit();
                header('Location: ' . app_url('preview.php?id=' . (int) $documentId . '&rotation_queued=1'));
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[FamilienDMS] Manual document rotation request failed: ' . get_class($exception));
            $error = 'Die Ausrichtung und Neuerkennung konnte nicht vorgemerkt werden.';
        }
    }
}
$rotation = (int) $document['rotation_degrees'];
if ($rotation < 0 || $rotation > 359) $rotation = 0;
$baseRotation = ((int) (floor(($rotation + 45) / 90) * 90)) % 360;
$fineRotation = $rotation - $baseRotation;
if ($fineRotation > 180) $fineRotation -= 360;
if ($fineRotation < -180) $fineRotation += 360;
$rotationOptions = '';
foreach ([0 => '0°', 90 => '90°', 180 => '180°', 270 => '270°'] as $degrees => $label) {
    $rotationOptions .= '<option value="' . $degrees . '"' . ($baseRotation === $degrees ? ' selected' : '') . '>' . $label . '</option>';
}
$rotationControls = '<input type="hidden" id="rotation-degrees-input" name="rotation_degrees" value="' . $rotation . '"><div class="preview-rotation-controls"><label for="preview-orientation-select">Grundausrichtung<select id="preview-orientation-select">' . $rotationOptions . '</select></label><label class="fine-rotation-label" for="preview-fine-rotation">Feineinstellung <span id="preview-fine-readout">' . ($fineRotation > 0 ? '+' : '') . $fineRotation . '°</span><input id="preview-fine-rotation" type="range" min="-15" max="15" step="1" value="' . $fineRotation . '" aria-describedby="preview-rotation-readout"></label><span id="preview-rotation-readout" class="rotation-total" aria-live="polite">Gesamt: ' . $rotation . '°</span></div>'; 
$rotationActions = $canSetRotation
    ? '<form method="post" class="rotation-save-form"><input type="hidden" name="_csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="action" value="save_rotation_and_reindex"><input type="hidden" name="document_id" value="' . (int) $documentId . '">' . $rotationControls . '<p class="form-hint">Wähle zuerst die Grundausrichtung und korrigiere die Schräglage danach Grad für Grad von −15° bis +15°. Jede Änderung ist sofort sichtbar. Beim nächsten OCR-Auftrag wird der Gesamtwinkel auf alle PDF-Seiten beziehungsweise das Bild angewendet. Das Original bleibt unverändert.</p><button type="submit">Drehung speichern &amp; OCR erneut starten</button></form>'
    : '<div class="rotation-view-only">' . str_replace('name="rotation_degrees"', '', $rotationControls) . '<p class="form-hint">Die Drehung wird nur in deiner Vorschau angezeigt.</p></div>';

header("Content-Security-Policy: default-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
$previewUrl = app_url('preview-file.php?id=' . (int) $document['id']);
$downloadUrl = app_url('download.php?id=' . (int) $document['id']);
$isPdf = $document['mime_type'] === 'application/pdf';
$kind = $isPdf ? 'PDF' : 'Bild';
$downloadLabel = $kind . ' herunterladen';
$body = '<section class="page-intro"><p class="eyebrow">' . $kind . '-Vorschau</p><h1>' . h($document['title']) . '</h1><p>Die Vorschau ist nur nach der Anmeldung verfügbar.</p><div class="preview-actions"><a class="button button-secondary" href="' . h(app_url('documents.php')) . '">← Zurück zum Archiv</a><a class="button" href="' . h($downloadUrl) . '">' . $downloadLabel . '</a></div></section>';
if (isset($_GET['rotation_queued'])) $body .= '<p class="alert-success" role="status">Drehung gespeichert. Die Texterkennung startet beim nächsten Lauf des geplanten OCR-Auftrags.</p>';
if ($error !== '') $body .= '<p class="alert-error" role="alert">' . h($error) . '</p>';
$body .= '<section class="panel preview-panel"><div class="preview-toolbar"><span>Vorschaugröße</span><div class="preview-size-options" role="group" aria-label="Vorschaugröße"><button class="button-secondary preview-size-button" type="button" data-preview-height="58" aria-pressed="false">Kompakt</button><button class="button-secondary preview-size-button" type="button" data-preview-height="75" aria-pressed="true">Lesen</button><button class="button-secondary preview-size-button" type="button" data-preview-height="86" aria-pressed="false">Groß</button></div><span class="preview-resize-hint">Auch an der unteren rechten Ecke ziehbar</span></div>' . $rotationActions;
if ($isPdf) {
    $body .= '<div class="pdf-preview-resizable" id="document-preview-resizable" data-document-rotation="' . $rotation . '"><iframe class="pdf-preview-frame" src="' . h($previewUrl) . '#zoom=page-width" title="PDF-Vorschau: ' . h($document['title']) . '" loading="eager" referrerpolicy="no-referrer"></iframe></div><p class="preview-fallback">Zum Lesen kannst du zusätzlich die Zoom-Steuerung im PDF-Betrachter deines Browsers verwenden. Falls dein Browser PDFs nicht direkt anzeigen kann, <a href="' . h($downloadUrl) . '">lade die Datei herunter</a>.</p>';
} else {
    $body .= '<div class="image-preview-resizable" id="document-preview-resizable" data-document-rotation="' . $rotation . '"><img class="image-preview-image" src="' . h($previewUrl) . '" alt="Vorschau von ' . h($document['title']) . '" loading="eager" referrerpolicy="no-referrer"></div><p class="preview-fallback">Das Bild wird passend in der Vorschaufläche angezeigt. Ziehe die Ecke, um den sichtbaren Bereich zu vergrößern.</p>';
}
$body .= '</section>';
page($kind . '-Vorschau', $body);
