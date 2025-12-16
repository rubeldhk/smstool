-- SQLite migration: add country support and extended recipient fields

ALTER TABLE campaigns ADD COLUMN country TEXT NOT NULL DEFAULT 'CA';
ALTER TABLE campaigns ADD COLUMN message_template TEXT;

ALTER TABLE recipients ADD COLUMN customer_name TEXT;
ALTER TABLE recipients ADD COLUMN receiver_name TEXT;
ALTER TABLE recipients ADD COLUMN country TEXT DEFAULT 'CA';
ALTER TABLE recipients ADD COLUMN rendered_message TEXT;
ALTER TABLE recipients ADD COLUMN provider_response TEXT;
ALTER TABLE recipients ADD COLUMN http_code INTEGER;
ALTER TABLE recipients ADD COLUMN last_error TEXT;
