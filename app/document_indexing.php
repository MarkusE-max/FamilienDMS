<?php
declare(strict_types=1);

require_once __DIR__ . '/document_storage.php';

final class DocumentIndexingDependencyException extends RuntimeException {}

/** Find a configured executable or a same-named binary in the task's PATH. */
function document_indexing_binary(string $environmentKey, string $fallback): string
{
    $configured = getenv($environmentKey);
    $candidate = is_string($configured) && trim($configured) !== '' ? trim($configured) : $fallback;
    if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
        throw new DocumentIndexingDependencyException('Required document text tool is unavailable.');
    }
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
        if (is_file($path) && is_executable($path)) return $path;
    }
    throw new DocumentIndexingDependencyException('Required document text tool is unavailable.');
}

/** Run one fixed-argument command without a shell, with bounded runtime and output. */
function document_indexing_run(array $arguments, int $timeoutSeconds = 120, int $maxOutputBytes = 5_000_000, ?string $input = null): string
{
    $stderrPath = tempnam(sys_get_temp_dir(), 'fdm-ocr-');
    if ($stderrPath === false) throw new RuntimeException('Unable to prepare text extraction.');
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrPath, 'w']];
    $process = proc_open($arguments, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { @unlink($stderrPath); throw new RuntimeException('Unable to start text extraction.'); }
    if ($input !== null) {
        $offset = 0;
        $inputLength = strlen($input);
        while ($offset < $inputLength) {
            $written = fwrite($pipes[0], substr($input, $offset, 1_048_576));
            if (!is_int($written) || $written < 1) {
                fclose($pipes[0]); fclose($pipes[1]); proc_terminate($process, 9); proc_close($process); @unlink($stderrPath);
                throw new RuntimeException('Unable to send the document to the OCR process.');
            }
            $offset += $written;
        }
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $output = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    do {
        $chunk = stream_get_contents($pipes[1]);
        if (is_string($chunk) && $chunk !== '') {
            $output .= $chunk;
            if (strlen($output) > $maxOutputBytes) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);
                @unlink($stderrPath);
                throw new RuntimeException('Extracted text exceeds the configured size limit.');
            }
        }
        $state = proc_get_status($process);
        if (!$state['running']) break;
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(100_000);
    } while (true);
    $tail = stream_get_contents($pipes[1]);
    if (is_string($tail)) $output .= $tail;
    fclose($pipes[1]);
    $reportedExitCode = is_array($state) ? (int) ($state['exitcode'] ?? -1) : -1;
    $exitCode = proc_close($process);
    if ($exitCode === -1 && $reportedExitCode >= 0) $exitCode = $reportedExitCode;
    $stderr = is_file($stderrPath) ? (string) file_get_contents($stderrPath) : '';
    @unlink($stderrPath);
    if ($timedOut) throw new RuntimeException('Text extraction exceeded its time limit.');
    if ($exitCode !== 0) {
        error_log('[FamilienDMS] Text extraction process failed: ' . substr($stderr, 0, 500));
        throw new RuntimeException('Text extraction process failed.');
    }
    return $output;
}

function document_indexing_temp_dir(array $config): string
{
    $root = realpath((string) ($config['storage_path'] ?? ''));
    if ($root === false || !is_dir($root) || !is_writable($root)) throw new RuntimeException('Private document storage is unavailable.');
    $directory = $root . DIRECTORY_SEPARATOR . '.ocr-work';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Unable to create private OCR workspace.');
    @chmod($directory, 0700);
    $realDirectory = realpath($directory);
    if ($realDirectory === false || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('OCR workspace must remain inside private storage.');
    return $realDirectory;
}

function document_indexing_read(array $document, array $config, bool $forceRealign = false): string
{
    $path = document_storage_file($config, (string) $document['storage_name']);
    if ($path === null || !is_readable($path)) throw new RuntimeException('Private document file is unavailable.');
    $fileSize = filesize($path);
    if ($fileSize === false || $fileSize > 20_000_000) throw new RuntimeException('Document exceeds the indexing size limit.');
    $mime = (string) $document['mime_type'];
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('Private document file could not be read.');
    $language = getenv('FAMILIENDMS_OCR_LANG') ?: 'deu+eng';
    if (!preg_match('/\A[a-zA-Z0-9_+.-]{2,80}\z/', $language)) throw new RuntimeException('OCR language setting is invalid.');

    if ($mime === 'image/jpeg' || $mime === 'image/png') {
        $ocrDocker = getenv('FAMILIENDMS_OCR_DOCKER');
        $ocrImage = getenv('FAMILIENDMS_OCR_IMAGE');
        if ($forceRealign) return document_indexing_ocrmypdf_image($contents, $language, $mime, true);
        if ((is_string($ocrDocker) && trim($ocrDocker) !== '') || (is_string($ocrImage) && trim($ocrImage) !== '')) {
            return document_indexing_ocrmypdf_image($contents, $language, $mime);
        }
        $containerName = getenv('FAMILIENDMS_TESSERACT_CONTAINER');
        if (is_string($containerName) && trim($containerName) !== '') {
            if (!preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/', $containerName)) throw new RuntimeException('Tesseract container name is invalid.');
            $docker = document_indexing_binary('FAMILIENDMS_OCR_DOCKER', 'docker');
            return trim(document_indexing_run([$docker, 'exec', '-i', trim($containerName), 'tesseract', 'stdin', 'stdout', '-l', $language, '--psm', '3'], 300, 5_000_000, $contents));
        }
        try {
            $tesseract = document_indexing_binary('FAMILIENDMS_TESSERACT', 'tesseract');
            return trim(document_indexing_run([$tesseract, $path, 'stdout', '-l', $language, '--psm', '3']));
        } catch (DocumentIndexingDependencyException) {
            return document_indexing_ocrmypdf_image($contents, $language, $mime);
        }
    }
    if ($mime !== 'application/pdf') throw new RuntimeException('Unsupported document type for text indexing.');
    if ($forceRealign) return document_indexing_ocrmypdf($contents, $language, true);

    try {
        $pdftotext = document_indexing_binary('FAMILIENDMS_PDFTOTEXT', 'pdftotext');
        $text = trim(document_indexing_run([$pdftotext, '-f', '1', '-l', '50', '-enc', 'UTF-8', '-nopgbrk', $path, '-']));
        if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') >= 30) return $text;
        $ocrDocker = getenv('FAMILIENDMS_OCR_DOCKER');
        $ocrImage = getenv('FAMILIENDMS_OCR_IMAGE');
        if ((is_string($ocrDocker) && trim($ocrDocker) !== '') || (is_string($ocrImage) && trim($ocrImage) !== '')) {
            return document_indexing_ocrmypdf($contents, $language);
        }
        $pdftoppm = document_indexing_binary('FAMILIENDMS_PDFTOPPM', 'pdftoppm');
        $tesseract = document_indexing_binary('FAMILIENDMS_TESSERACT', 'tesseract');
        $workspace = document_indexing_temp_dir($config);
        $prefix = $workspace . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        $text = '';
        try {
            document_indexing_run([$pdftoppm, '-f', '1', '-l', '20', '-r', '180', '-scale-to', '1800', '-png', $path, $prefix]);
            $images = glob($prefix . '-*.png') ?: [];
            sort($images, SORT_NATURAL);
            foreach (array_slice($images, 0, 20) as $imagePath) {
                $pageText = trim(document_indexing_run([$tesseract, $imagePath, 'stdout', '-l', $language, '--psm', '3']));
                if ($pageText !== '') $text .= $pageText . "\n\n";
                if (strlen($text) >= 4_000_000) { $text = substr($text, 0, 4_000_000); break; }
            }
            return trim($text);
        } finally {
            foreach (glob($prefix . '-*.png') ?: [] as $imagePath) if (is_file($imagePath)) @unlink($imagePath);
        }
    } catch (DocumentIndexingDependencyException) {
        return document_indexing_ocrmypdf($contents, $language);
    }
}

/** OCR one uploaded image in a one-shot OCRmyPDF container with auto-orientation and deskew. */
function document_indexing_ocrmypdf_image(string $contents, string $language, string $mime, bool $forceRealign = false): string
{
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) throw new RuntimeException('Unsupported image type for OCR.');
    $docker = document_indexing_binary('FAMILIENDMS_OCR_DOCKER', 'docker');
    $image = getenv('FAMILIENDMS_OCR_IMAGE') ?: 'jbarlow83/ocrmypdf-alpine';
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.\/@:-]{0,190}\z/', $image)) throw new RuntimeException('OCR image name is invalid.');
    $extension = $mime === 'image/png' ? 'png' : 'jpg';
    $realignOption = $forceRealign ? ' --rotate-pages-threshold 2.0' : '';
    $script = 'cat > /tmp/fdms-source.' . $extension . ' && ocrmypdf --image-dpi 300 --rotate-pages' . $realignOption . ' --deskew --output-type none --sidecar - --pages 1 --jobs 1 -l \"$1\" /tmp/fdms-source.' . $extension . ' -';
    $command = [
        $docker, 'run', '--rm', '-i', '--network', 'none', '--memory', '2g', '--cpus', '1', '--pids-limit', '128',
        '--tmpfs', '/tmp:rw,nosuid,nodev,size=1g', '--entrypoint', '/bin/sh', $image, '-c', $script, 'fdms-ocr', $language,
    ];
    return trim(document_indexing_run($command, 600, 5_000_000, $contents));
}

/** OCR a PDF through an isolated one-shot OCRmyPDF container. */
function document_indexing_ocrmypdf(string $contents, string $language, bool $forceRealign = false): string
{
    $docker = document_indexing_binary('FAMILIENDMS_OCR_DOCKER', 'docker');
    $image = getenv('FAMILIENDMS_OCR_IMAGE') ?: 'jbarlow83/ocrmypdf-alpine';
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.\/@:-]{0,190}\z/', $image)) throw new RuntimeException('OCR image name is invalid.');
    $command = [
        $docker, 'run', '--rm', '-i', '--network', 'none', '--memory', '2g', '--cpus', '1', '--pids-limit', '128',
        '--tmpfs', '/tmp:rw,nosuid,nodev,size=1g', $image,
        '--force-ocr', '--rotate-pages',
    ];
    if ($forceRealign) array_push($command, '--rotate-pages-threshold', '2.0');
    array_push($command, '--deskew', '--output-type', 'none', '--sidecar', '-', '--pages', '1-20', '--jobs', '1', '-l', $language, '-', '-');
    return trim(document_indexing_run($command, 1800, 5_000_000, $contents));
}
