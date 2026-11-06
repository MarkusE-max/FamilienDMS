ALTER TABLE document_text
    ADD COLUMN error_detail VARCHAR(500) NULL AFTER error_code;

UPDATE document_text
SET error_detail = 'Dieser Fehler trat vor der detaillierten Fehlerdiagnose auf. OCR erneut starten, um den konkreten Grund zu erfassen.'
WHERE status = 'failed' AND error_detail IS NULL;
