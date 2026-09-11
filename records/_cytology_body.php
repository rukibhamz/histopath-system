<?php
/**
 * Renders a full cytology report in the same paper-form layout as histology.
 * Expects $r (the record row) in scope. Shared by cytology_print.php and
 * review.php, so a reviewer sees exactly what will be printed.
 *
 * Free-text fields go through rf_text(), which hides the lone "0" the Access
 * import writes into empty legacy columns. Age, Lab No and Hosp No are shown
 * exactly as recorded, since 0 could be genuine there.
 */
require_once __DIR__ . '/_report_form.php';
?>
<div class="rf">
    <?= report_letterhead('Cytology Report') ?>

    <div class="rf__frame">
        <div class="rf__row rf__row--6">
            <?= rf_field('Hosp No', $r['hosp_no'], 'right') ?>
            <?= rf_field('Surname', $r['surname'], 'right') ?>
            <?= rf_field('Other Names', rf_text($r['other_names']), 'right') ?>
            <?= rf_field('Age (Years)', $r['age'], 'right') ?>
            <?= rf_field('Sex', rf_text($r['sex']), 'right') ?>
            <?= rf_field('Lab No', format_lab_no($r['lab_no'], $r['lab_year'] ?? null)) ?>
        </div>

        <div class="rf__row rf__row--5">
            <?= rf_field('Requesting Hospital', rf_text($r['requesting_hospital'])) ?>
            <?= rf_field('Ward/Clinic', rf_text($r['ward_clinic'])) ?>
            <?= rf_field('Ethnic Group', rf_text($r['ethnic_group'])) ?>
            <?= rf_field('Date of Collection', rf_date($r['date_of_collection']), 'right') ?>
            <?= rf_field('Signout Date', rf_date($r['signout_date']), 'right') ?>
        </div>

        <div class="rf__row rf__row--4">
            <?= rf_field('LMP', rf_date($r['lmp'])) ?>
            <?= rf_field("Patient's Tel No", rf_text($r['patients_tel_no'])) ?>
            <?= rf_field('Previous Lab No', rf_text($r['previous_lab_no'])) ?>
            <?= rf_field('Clinician', rf_text($r['clinician']), 'right') ?>
        </div>

        <div class="rf__row rf__row--3">
            <?= rf_field('Drug History', rf_text($r['drug_history'])) ?>
            <?= rf_field('Radiation', rf_text($r['radiation'])) ?>
            <?= rf_field("Clinician's Tel No", rf_text($r['clinician_tel_no']), 'right') ?>
        </div>

        <?= rf_section('Clinical History', rf_text($r['clinical_history'])) ?>
        <?= rf_section('Nature of Specimen', rf_text($r['nature_of_specimen'])) ?>
        <?= rf_section('Microscopy', rf_text($r['microscopy'])) ?>
        <?= rf_section('Diagnosis', rf_text($r['diagnosis'])) ?>
        <?= rf_section('Recommendation', rf_text($r['recommendation']), 'tall') ?>

        <div class="rf__row rf__row--sign">
            <?= rf_field('Resident Doctor(s)', rf_text($r['resident_doctors'])) ?>
            <?= rf_field('Consultant Pathologist(s)', rf_text($r['consultant_pathologists']), 'right') ?>
        </div>

        <?= rf_printed_on() ?>
    </div>

    <?= rf_extras([
        'Cost' => rf_recorded($r['cost']) ? number_format((float)$r['cost'], 2) : '',
    ]) ?>
</div>
