<?php
/**
 * Renders a full histology report in the department's paper-form layout.
 * Expects $r (the record row) in scope. Shared by histology_print.php and
 * review.php, so a reviewer sees exactly what will be printed.
 */
require_once __DIR__ . '/_report_form.php';
?>
<div class="rf">
    <?= report_letterhead('Histology Report') ?>

    <div class="rf__frame">
        <div class="rf__row rf__row--6">
            <?= rf_field('Hospital No', $r['hospital_no'], 'right') ?>
            <?= rf_field('Surname', $r['surname'], 'right') ?>
            <?= rf_field('Other Names', $r['other_names'], 'right') ?>
            <?= rf_field('Age (Years)', $r['age'], 'right') ?>
            <?= rf_field('Sex', $r['sex'], 'right') ?>
            <?= rf_field('Lab No', $r['lab_no']) ?>
        </div>

        <div class="rf__row rf__row--5">
            <?= rf_field('Requesting Hospital', $r['requesting_hospital']) ?>
            <?= rf_field('Ward/Clinic', $r['ward_clinic']) ?>
            <?= rf_field('Ethnic Group/Race', $r['ethnic_group']) ?>
            <?= rf_field('Date of Collection', rf_date($r['date_of_collection']), 'right') ?>
            <?= rf_field('Date Out', rf_date($r['date_out']), 'right') ?>
        </div>

        <div class="rf__row rf__row--4">
            <?= rf_field('Special Requests', $r['special_requests']) ?>
            <?= rf_field('Specimen Status', $r['specimen_status']) ?>
            <?= rf_field('Previous Lab No', $r['previous_lab_no']) ?>
            <?= rf_field('Clinician', $r['clinician'], 'right') ?>
        </div>

        <?= rf_section('Clinical History', $r['clinical_history']) ?>
        <?= rf_section('Provisional Diagnosis', $r['provisional_diagnosis']) ?>
        <?= rf_section('Nature of Specimen', $r['nature_of_specimen']) ?>
        <?= rf_section('Gross', $r['gross']) ?>
        <?= rf_section('Microscopy', $r['microscopy']) ?>
        <?= rf_section('Further Tests', $r['further_tests'], 'tall') ?>

        <?php // Not on the standard form, but clinical findings - printed whenever recorded. ?>
        <?php if (rf_recorded($r['bone_marrow'])): ?>
            <?= rf_section('Bone Marrow', $r['bone_marrow']) ?>
        <?php endif; ?>
        <?php if (rf_recorded($r['lymphomas'])): ?>
            <?= rf_section('Lymphomas', $r['lymphomas']) ?>
        <?php endif; ?>

        <?= rf_section('Diagnosis', $r['diagnosis']) ?>
        <?= rf_section('Remarks', $r['remarks'], 'tall') ?>

        <div class="rf__row rf__row--sign">
            <?= rf_field('Resident Doctor(s)', $r['resident_doctors']) ?>
            <?= rf_field('Consultant Pathologist(s)', $r['consultant_pathologists'], 'right') ?>
        </div>

        <?= rf_printed_on() ?>
    </div>

    <?= rf_extras([
        'Adverse incidents' => $r['adverse_incidents'],
        'Cost'              => rf_filled($r['cost']) ? number_format((float)$r['cost'], 2) : '',
    ]) ?>
</div>
