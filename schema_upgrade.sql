-- ============================================
-- Upgrade for installations created with the original schema.sql
-- ============================================
-- Safe to run more than once. If you are setting up a NEW database,
-- ignore this file and load schema.sql instead - it already includes everything here.
--
--   psql -h localhost -U histopath_app -d histopath_system -f schema_upgrade.sql

BEGIN;

-- Login rate limiting (used by login.php to lock out repeated failures)
CREATE TABLE IF NOT EXISTS login_attempts (
    id              SERIAL PRIMARY KEY,
    username        VARCHAR(50),
    ip_address      VARCHAR(45),
    successful      BOOLEAN DEFAULT FALSE,
    attempted_at    TIMESTAMP DEFAULT NOW()
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_histology_status        ON histology_reports (status);
CREATE INDEX IF NOT EXISTS idx_histology_submitted_by  ON histology_reports (submitted_by);
CREATE INDEX IF NOT EXISTS idx_histology_created_at    ON histology_reports (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_histology_collection    ON histology_reports (date_of_collection);
CREATE INDEX IF NOT EXISTS idx_histology_surname       ON histology_reports (surname);

CREATE INDEX IF NOT EXISTS idx_cytology_status         ON cytology_reports (status);
CREATE INDEX IF NOT EXISTS idx_cytology_submitted_by   ON cytology_reports (submitted_by);
CREATE INDEX IF NOT EXISTS idx_cytology_created_at     ON cytology_reports (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cytology_collection     ON cytology_reports (date_of_collection);
CREATE INDEX IF NOT EXISTS idx_cytology_surname        ON cytology_reports (surname);

CREATE INDEX IF NOT EXISTS idx_logs_created_at         ON access_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_logs_user               ON access_logs (user_id);
CREATE INDEX IF NOT EXISTS idx_logs_action             ON access_logs (action);

CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup   ON login_attempts (username, ip_address, attempted_at);

-- Keep updated_at honest on every UPDATE
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_histology_updated_at ON histology_reports;
CREATE TRIGGER trg_histology_updated_at
    BEFORE UPDATE ON histology_reports
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_cytology_updated_at ON cytology_reports;
CREATE TRIGGER trg_cytology_updated_at
    BEFORE UPDATE ON cytology_reports
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- access_logs records blocked login attempts before anyone is signed in,
-- so user_id has to accept NULL.
ALTER TABLE access_logs ALTER COLUMN user_id DROP NOT NULL;

COMMIT;
