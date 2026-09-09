-- ============================================
-- Histopathology Records System - SQLite Schema
-- ============================================
-- The SQLite equivalent of schema.sql. The setup wizard loads whichever of the
-- two matches the database you choose; you only need this file if you are
-- creating the database by hand:
--
--   sqlite3 /var/lib/histopath/histopath.sqlite < schema.sqlite.sql

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name             TEXT NOT NULL,
    email                 TEXT UNIQUE NOT NULL,
    username              TEXT UNIQUE NOT NULL,
    password_hash         TEXT NOT NULL,
    role                  TEXT NOT NULL CHECK (role IN ('admin', 'reviewer', 'staff')),
    is_active             INTEGER DEFAULT 1,
    must_reset_password   INTEGER DEFAULT 1,
    created_at            TEXT DEFAULT (datetime('now','localtime')),
    last_login            TEXT
);

CREATE TABLE access_logs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER REFERENCES users(id),
    action          TEXT NOT NULL,
    target_table    TEXT,
    target_id       INTEGER,
    ip_address      TEXT,
    details         TEXT,
    created_at      TEXT DEFAULT (datetime('now','localtime'))
);

CREATE TABLE system_settings (
    setting_key     TEXT PRIMARY KEY,
    setting_value   TEXT,
    updated_by      INTEGER REFERENCES users(id),
    updated_at      TEXT DEFAULT (datetime('now','localtime'))
);

CREATE TABLE histology_reports (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    lab_no                  TEXT UNIQUE NOT NULL,
    surname                 TEXT NOT NULL,
    other_names             TEXT,
    age                     INTEGER,
    sex                     TEXT CHECK (sex IS NULL OR sex IN ('MALE','FEMALE')),
    ethnic_group            TEXT,
    hospital_no             TEXT,
    requesting_hospital     TEXT,
    ward_clinic             TEXT,
    date_of_collection      TEXT,
    clinical_history        TEXT,
    nature_of_specimen      TEXT,
    special_requests        TEXT,
    provisional_diagnosis   TEXT,
    previous_lab_no         TEXT,
    clinician               TEXT,
    specimen_status         TEXT,
    gross                   TEXT,
    microscopy              TEXT,
    further_tests           TEXT,
    bone_marrow             TEXT,
    lymphomas               TEXT,
    diagnosis               TEXT,
    remarks                 TEXT,
    resident_doctors        TEXT,
    consultant_pathologists TEXT,
    date_out                TEXT,
    adverse_incidents       TEXT,
    cost                    REAL,
    status                  TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    submitted_by            INTEGER REFERENCES users(id),
    reviewed_by             INTEGER REFERENCES users(id),
    reviewer_comments       TEXT,
    reviewed_at             TEXT,
    created_at              TEXT DEFAULT (datetime('now','localtime')),
    updated_at              TEXT DEFAULT (datetime('now','localtime'))
);

CREATE TABLE cytology_reports (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    lab_no                  TEXT UNIQUE NOT NULL,
    surname                 TEXT NOT NULL,
    other_names             TEXT,
    age                     INTEGER,
    sex                     TEXT CHECK (sex IS NULL OR sex IN ('MALE','FEMALE')),
    ethnic_group            TEXT,
    requesting_hospital     TEXT,
    hosp_no                 TEXT,
    ward_clinic             TEXT,
    patients_tel_no         TEXT,
    date_of_collection      TEXT,
    clinical_history        TEXT,
    lmp                     TEXT,
    drug_history            TEXT,
    radiation               TEXT,
    previous_lab_no         TEXT,
    nature_of_specimen      TEXT,
    clinician               TEXT,
    clinician_tel_no        TEXT,
    microscopy              TEXT,
    diagnosis               TEXT,
    recommendation          TEXT,
    resident_doctors        TEXT,
    consultant_pathologists TEXT,
    signout_date            TEXT,
    cost                    REAL,
    status                  TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    submitted_by            INTEGER REFERENCES users(id),
    reviewed_by             INTEGER REFERENCES users(id),
    reviewer_comments       TEXT,
    reviewed_at             TEXT,
    created_at              TEXT DEFAULT (datetime('now','localtime')),
    updated_at              TEXT DEFAULT (datetime('now','localtime'))
);

CREATE TABLE login_attempts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT,
    ip_address      TEXT,
    successful      INTEGER DEFAULT 0,
    attempted_at    TEXT DEFAULT (datetime('now','localtime'))
);

-- ============================================
-- Indexes
-- ============================================

CREATE INDEX idx_histology_status        ON histology_reports (status);
CREATE INDEX idx_histology_submitted_by  ON histology_reports (submitted_by);
CREATE INDEX idx_histology_created_at    ON histology_reports (created_at DESC);
CREATE INDEX idx_histology_collection    ON histology_reports (date_of_collection);
CREATE INDEX idx_histology_surname       ON histology_reports (surname);

CREATE INDEX idx_cytology_status         ON cytology_reports (status);
CREATE INDEX idx_cytology_submitted_by   ON cytology_reports (submitted_by);
CREATE INDEX idx_cytology_created_at     ON cytology_reports (created_at DESC);
CREATE INDEX idx_cytology_collection     ON cytology_reports (date_of_collection);
CREATE INDEX idx_cytology_surname        ON cytology_reports (surname);

CREATE INDEX idx_logs_created_at         ON access_logs (created_at DESC);
CREATE INDEX idx_logs_user               ON access_logs (user_id);
CREATE INDEX idx_logs_action             ON access_logs (action);

CREATE INDEX idx_login_attempts_lookup   ON login_attempts (username, ip_address, attempted_at);

-- ============================================
-- Keep updated_at honest on every UPDATE
-- ============================================
-- The WHEN guard stops the trigger firing on its own write, which matters if
-- recursive_triggers is ever turned on.

CREATE TRIGGER trg_histology_updated_at
AFTER UPDATE ON histology_reports
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE histology_reports SET updated_at = datetime('now','localtime') WHERE id = NEW.id;
END;

CREATE TRIGGER trg_cytology_updated_at
AFTER UPDATE ON cytology_reports
FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
BEGIN
    UPDATE cytology_reports SET updated_at = datetime('now','localtime') WHERE id = NEW.id;
END;
