<?php
/**
 * Building blocks for the report form - the boxed layout of the department's
 * paper histology and cytology reports. Used by _histology_body.php and
 * _cytology_body.php, which are shared by the view, review and print screens.
 */

/** Stored dates are Y-m-d; the paper form shows them as dd/mm/yyyy. */
function rf_date(?string $value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0') return '';   // "0" is an import placeholder, never a date
    $date = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $date ? $date->format('d/m/Y') : $value;
}

/** A small label box with its value box beneath. Pass 'right' to right-align the value. */
function rf_field(string $label, $value, string $align = ''): string {
    $class = 'rf__value' . ($align === 'right' ? ' rf__value--right' : '');
    return '<div class="rf__field">'
         . '<div class="rf__label">' . e($label) . '</div>'
         . '<div class="' . $class . '">' . e((string)($value ?? '')) . '</div>'
         . '</div>';
}

/** A full-width narrative section. Pass 'tall' for sections that are often left blank to fill in. */
function rf_section(string $label, $text, string $size = ''): string {
    $class = 'rf__block' . ($size === 'tall' ? ' rf__block--tall' : '');
    return '<div class="' . $class . '">'
         . '<div class="rf__label">' . e($label) . '</div>'
         . '<div class="rf__prose">' . e((string)($text ?? '')) . '</div>'
         . '</div>';
}

/** True when a free-text field has something in it. */
function rf_filled($value): bool {
    return trim((string)($value ?? '')) !== '';
}

/**
 * Like rf_filled(), but also treats a lone "0" as not recorded. The Access import
 * stored 0 in the Bone Marrow and Lymphomas columns of every legacy report, so
 * those sections would otherwise print a meaningless "0" on each one.
 */
function rf_recorded($value): bool {
    $value = trim((string)($value ?? ''));
    return $value !== '' && $value !== '0';
}

/**
 * The value to show for a free-text field, blank when it only holds the import's
 * "0" placeholder. Use it only where a lone 0 can never be a real answer - not for
 * age, lab numbers or other identifiers.
 */
function rf_text($value): string {
    return rf_recorded($value) ? (string)$value : '';
}

/** The date the report was produced, as it appears in the bottom corner of the form. */
function rf_printed_on(): string {
    return '<div class="rf__printed">' . e(date('l, j F Y')) . '</div>';
}

/**
 * Fields the system records that are not part of the official printout.
 * Shown on screen beneath the form, never printed. Empty values are skipped.
 */
function rf_extras(array $pairs): string {
    $pairs = array_filter($pairs, 'rf_filled');
    if (!$pairs) return '';

    $html = '<div class="rf-extra no-print">'
          . '<div class="rf-extra__title">Recorded, not shown on the printed report</div>'
          . '<dl class="rf-extra__list">';
    foreach ($pairs as $label => $value) {
        $html .= '<div><dt>' . e($label) . '</dt><dd>' . e((string)$value) . '</dd></div>';
    }
    return $html . '</dl></div>';
}
