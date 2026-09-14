<?php
/**
 * Parsing and column-guessing helpers for the legacy Access CSV import.
 * Kept separate from admin/import.php so they can be exercised on their own.
 */

/** Normalize a CSV header for fuzzy matching against DB column names */
function normalize_header(string $h): string {
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}

/** Try to guess which DB column a CSV header maps to */
function guess_column(string $header, array $db_columns): ?string {
    $norm = normalize_header($header);
    // Access AutoNumber must never land in cytology/histology id.
    if (in_array($norm, ['id', 'record_id', 'report_id'], true)) {
        return null;
    }
    $aliases = [
        'year' => ['lab_year'],
        'lab_year' => ['lab_year'],
        'accession_year' => ['lab_year'],
        'hosp_no' => ['hospital_no', 'hosp_no'],
        'hospital_no' => ['hospital_no', 'hosp_no'],
        'age_years' => ['age'],
        'previous_lab_numbers' => ['previous_lab_no'],
        'patients_tel_no' => ['patients_tel_no'],
        'clinician_s_tel_no' => ['clinician_tel_no'],
        'signout_date' => ['signout_date'],
        'sign_out_date' => ['signout_date'],
        'date_out' => ['date_out'],
        'ward_clinic' => ['ward_clinic'],
    ];
    if (isset($aliases[$norm])) {
        foreach ($aliases[$norm] as $candidate) {
            if (in_array($candidate, $db_columns, true)) return $candidate;
        }
    }
    // Direct match
    if (in_array($norm, $db_columns, true)) return $norm;
    // Contains-based fallback. Skip short tokens ("id", "no") so they cannot
    // match inside longer names such as resident_doctors or hospital_no.
    foreach ($db_columns as $col) {
        if (strlen($col) >= 3 && str_contains($norm, $col)) return $col;
        if (strlen($norm) >= 4 && str_contains($col, $norm)) return $col;
    }
    return null;
}

/** Parse dates from common Nigerian/Access export formats into YYYY-MM-DD */
function parse_date(?string $value): ?string {
    if (!$value || trim($value) === '') return null;
    $value = trim($value);
    foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y', 'd/m/y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $value);
        if ($d !== false) return $d->format('Y-m-d');
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/**
 * Legacy exports spell sex every which way (M, F, Male, female...).
 * The column has a CHECK constraint, so normalise rather than reject the row.
 */
function parse_sex(?string $value): ?string {
    $v = strtoupper(trim((string)$value));
    if ($v === '') return null;
    if (str_starts_with($v, 'M')) return 'MALE';
    if (str_starts_with($v, 'F')) return 'FEMALE';
    return null;
}

/** Strip currency symbols and separators off a legacy cost value. */
function parse_number(?string $value): ?string {
    if ($value === null) return null;
    $clean = preg_replace('/[^0-9.\-]/', '', $value);
    return ($clean === '' || !is_numeric($clean)) ? null : $clean;
}

/**
 * Calendar year the admin assigns to a whole import file.
 * Access AutoNumbers restart each year, so this is what keeps 2023/2070
 * distinct from 2024/2070 without rewriting the lab number itself.
 */
function parse_import_year(mixed $value): ?int {
    if ($value === null || $value === '') return null;
    if (!ctype_digit((string)$value)) return null;
    $year = (int)$value;
    return ($year >= 1990 && $year <= 2100) ? $year : null;
}
