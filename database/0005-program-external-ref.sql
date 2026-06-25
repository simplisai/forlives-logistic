-- Add external_ref to programs so external systems (Supabase/stores) can map
-- a store to a Numok program idempotently (e.g. external_ref = store slug).
-- Nullable + unique: existing manually-created programs keep external_ref = NULL.
ALTER TABLE programs
ADD COLUMN external_ref VARCHAR(100) NULL DEFAULT NULL AFTER status;

ALTER TABLE programs
ADD UNIQUE KEY uniq_external_ref (external_ref);
