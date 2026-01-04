-- TOM3 - Erweitere document_attachments.entity_type um 'import_batch'
-- Ermöglicht saubere Verknüpfung von Import-Dateien zu Import-Batches

ALTER TABLE document_attachments 
MODIFY COLUMN entity_type ENUM(
    'org', 
    'person', 
    'case', 
    'project', 
    'task', 
    'email_message', 
    'email_thread',
    'import_batch'
) NOT NULL;

