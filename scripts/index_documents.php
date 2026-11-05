<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/document_indexing.php';

$limit = 1;
$retryFailed = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--limit=([1-9][0-9]?)\z/', $argument, $match)) $limit = min(20, (int) $match[1]);
    elseif ($argument === '--retry-failed') $retryFailed = true;
    else { fwrite(STDERR, "Usage: php scripts/index_documents.php [--limit=1..20] [--retry-failed]\n"); exit(64); }
}

$pdo = db();
$sql = "SELECT d.id, d.storage_name, d.mime_type FROM documents d JOIN document_text t ON t.document_id = d.id WHERE t.status = 'pending' OR (t.status = 'processing' AND t.lease_until < NOW())";
if ($retryFailed) $sql .= " OR t.status = 'failed'";
$sql .= ' ORDER BY d.id LIMIT ' . $limit;
$documents = $pdo->query($sql)->fetchAll();
if (!$documents) { fwrite(STDOUT, "Keine Dokumente zur Verarbeitung.\n"); exit(0); }

$completed = 0;
foreach ($documents as $document) {
    $id = (int) $document['id'];
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT status, lease_until FROM document_text WHERE document_id = ? FOR UPDATE');
        $lock->execute([$id]);
        $current = $lock->fetch();
        $eligible = $current && ($current['status'] === 'pending' || ($current['status'] === 'processing' && $current['lease_until'] !== null && strtotime((string) $current['lease_until']) < time()) || ($retryFailed && $current['status'] === 'failed'));
        if (!$eligible) { $pdo->commit(); continue; }
        $claim = $pdo->prepare("UPDATE document_text SET status = 'processing', lease_until = DATE_ADD(NOW(), INTERVAL 2 HOUR), attempts = attempts + 1, error_code = NULL WHERE document_id = ?");
        $claim->execute([$id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "Dokument {$id}: konnte nicht reserviert werden.\n");
        continue;
    }

    try {
        $text = document_indexing_read($document, app_config());
        $status = $text === '' ? 'empty' : 'indexed';
        $save = $pdo->prepare('UPDATE document_text SET status = ?, content = ?, lease_until = NULL, error_code = NULL, indexed_at = NOW() WHERE document_id = ?');
        $save->execute([$status, $text === '' ? null : $text, $id]);
        fwrite(STDOUT, "Dokument {$id}: {$status}.\n");
        $completed++;
    } catch (DocumentIndexingDependencyException $exception) {
        $pdo->prepare("UPDATE document_text SET status = 'pending', lease_until = NULL, error_code = 'tool_unavailable' WHERE document_id = ?")->execute([$id]);
        fwrite(STDERR, "OCR/textract program not available; install and configure dependencies, then rerun.\n");
        exit(2);
    } catch (Throwable $exception) {
        $pdo->prepare("UPDATE document_text SET status = 'failed', lease_until = NULL, error_code = 'processing_failed' WHERE document_id = ?")->execute([$id]);
        error_log('[FamilienDMS] Text indexing failed for document ' . $id . ': ' . get_class($exception));
        fwrite(STDERR, "Dokument {$id}: Verarbeitung fehlgeschlagen; Fehlerprotokoll prüfen.\n");
    }
}
fwrite(STDOUT, "Verarbeitet: {$completed}/" . count($documents) . " Dokumente.\n");
