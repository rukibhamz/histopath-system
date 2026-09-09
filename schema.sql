-- ============================================
-- Histopathology Records System - Full Schema
-- ============================================

CREATE TABLE users (
    id                    SERIAL PRIMARY KEY,
    full_name             VARCHAR(150) NOT NULL,
    email                 VARCHAR(150) UNIQUE NOT NULL,
    username              VARCHAR(50) UNIQUE NOT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    role                  VARCHAR(20) NOT NULL CHECK (role IN ('admin', 'reviewer', 'staff')),
    is_active             BOOLEAN DEFAULT TRUE,
    must_reset_password   BOOLEAN DEFAULT TRUE,
    created_at            TIMESTAMP DEFAULT NOW(),
    last_login            TIMESTAMP
);

CREATE TABLE access_logs (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER REFERENCES users(id),
    action          VARCHAR(50) NOT NULL,
    target_table    VARCHAR(50),
    target_id       INTEGER,
    ip_address      VARCHAR(45),
    details         TEXT,
    created_at      TIMESTAMP DEFAULT NOW()
);

CREATE TABLE system_settings (
    setting_key     VARCHAR(50) PRIMARY KEY,
    setting_value   TEXT,
    updated_by      INTEGER REFERENCES users(id),
    updated_at      TIMESTAMP DEFAULT NOW()
);

CREATE TABLE histology_reports (
    id                      SERIAL PRIMARY KEY,
    lab_no                  VARCHAR(20) UNIQUE NOT NULL,
    surname                 VARCHAR(100) NOT NULL,
    other_names             VARCHAR(150),
    age                     INTEGER,
    sex                     VARCHAR(10) CHECK (sex IN ('MALE','FEMALE')),
    ethnic_group            VARCHAR(50),
    hospital_no             VARCHAR(20),
    requesting_hospital     VARCHAR(100),
    ward_clinic             VARCHAR(100),
    date_of_collection      DATE,
    clinical_history        TEXT,
    nature_of_specimen      TEXT,
    special_requests        VARCHAR(255),
    provisional_diagnosis   TEXT,
    previous_lab_no         VARCHAR(20),
    clinician               VARCHAR(100),
    specimen_status         VARCHAR(50),
    gross                   TEXT,
    microscopy              TEXT,
    further_tests           TEXT,
    bone_marrow             TEXT,
    lymphomas               TEXT,
    diagnosis               TEXT,
    remarks                 TEXT,
    resident_doctors        TEXT,
    consultant_pathologists TEXT,
    date_out                DATE,
    adverse_incidents       TEXT,
    cost                    NUMERIC(10,2),
    status                  VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    submitted_by            INTEGER REFERENCES users(id),
    reviewed_by             INTEGER REFERENCES users(id),
    reviewer_comments       TEXT,
    reviewed_at             TIMESTAMP,
    created_at              TIMESTAMP DEFAULT NOW(),
    updated_at              TIMESTAMP DEFAULT NOW()
);

CREATE TABLE cytology_reports (
    id                      SERIAL PRIMARY KEY,
    lab_no                  VARCHAR(20) UNIQUE NOT NULL,
    surname                 VARCHAR(100) NOT NULL,
    other_names             VARCHAR(150),
    age                     INTEGER,
    sex                     VARCHAR(10) CHECK (sex IN ('MALE','FEMALE')),
    ethnic_group            VARCHAR(50),
    requesting_hospital     VARCHAR(100),
    hosp_no                 VARCHAR(20),
    ward_clinic             VARCHAR(100),
    patients_tel_no         VARCHAR(20),
    date_of_collection      DATE,
    clinical_history        TEXT,
    lmp                     DATE,
    drug_history            VARCHAR(255),
    radiation               VARCHAR(255),
    previous_lab_no         VARCHAR(20),
    nature_of_specimen      TEXT,
    clinician               VARCHAR(100),
    clinician_tel_no        VARCHAR(20),
    microscopy              TEXT,
    diagnosis               TEXT,
    recommendation          TEXT,
    resident_doctors        TEXT,
    consultant_pathologists TEXT,
    signout_date            DATE,
    cost                    NUMERIC(10,2),
    status                  VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    submitted_by            INTEGER REFERENCES users(id),
    reviewed_by             INTEGER REFERENCES users(id),
    reviewer_comments       TEXT,
    reviewed_at             TIMESTAMP,
    created_at              TIMESTAMP DEFAULT NOW(),
    updated_at              TIMESTAMP DEFAULT NOW()
);

CREATE TABLE login_attempts (
    id              SERIAL PRIMARY KEY,
    username        VARCHAR(50),
    ip_address      VARCHAR(45),
    successful      BOOLEAN DEFAULT FALSE,
    attempted_at    TIMESTAMP DEFAULT NOW()
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

CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_histology_updated_at
    BEFORE UPDATE ON histology_reports
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER trg_cytology_updated_at
    BEFORE UPDATE ON cytology_reports
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Seed one initial admin account so you can log in and create the rest.
-- Replace 'CHANGE_ME_HASH' with a real hash generated via:
--   php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
INSERT INTO users (full_name, email, username, password_hash, role, is_active, must_reset_password)
VALUES ('System Administrator', 'admin@nha.gov.ng', 'admin', 'CHANGE_ME_HASH', 'admin', TRUE, FALSE);
