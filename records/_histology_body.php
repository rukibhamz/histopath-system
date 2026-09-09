<?php
/**
 * Renders a full histology report. Expects $r (the record row) in scope.
 * Shared by histology_print.php and review.php so a reviewer sees exactly
 * what will be printed - every field, not a summary.
 */
$logo = setting('logo_path');
?>
<div class="report__head">
    <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
    <div class="report__org"><?= e(setting('hospital_name')) ?></div>
    <div class="report__dept"><?= e(setting('department_name')) ?></div>
    <div class="report__title">Histology Report</div>
</div>

<div class="report__grid">
    <div class="report__cell"><span class="report__key">Lab No</span><span class="report__val"><?= e($r['lab_no']) ?></span></div>
    <div class="report__cell"><span class="report__key">Hospital No</span><span class="report__val"><?= e($r['hospital_no']) ?></span></div>
    <div class="report__cell"><span class="report__key">Patient</span><span class="report__val"><?= e(trim($r['surname'] . ' ' . $r['other_names'])) ?></span></div>
    <div class="report__cell"><span class="report__key">Age / Sex</span><span class="report__val"><?= e(trim(($r['age'] ?? '') . ' / ' . ($r['sex'] ?? ''), ' /')) ?></span></div>

    <div class="report__cell"><span class="report__key">Requesting Hospital</span><span class="report__val"><?= e($r['requesting_hospital']) ?></span></div>
    <div class="report__cell"><span class="report__key">Ward / Clinic</span><span class="report__val"><?= e($r['ward_clinic']) ?></span></div>
    <div class="report__cell"><span class="report__key">Ethnic Group / Race</span><span class="report__val"><?= e($r['ethnic_group']) ?></span></div>
    <div class="report__cell"><span class="report__key">Date of Collection</span><span class="report__val"><?= e($r['date_of_collection']) ?></span></div>

    <div class="report__cell"><span class="report__key">Special Requests</span><span class="report__val"><?= e($r['special_requests']) ?></span></div>
    <div class="report__cell"><span class="report__key">Specimen Status</span><span class="report__val"><?= e($r['specimen_status']) ?></span></div>
    <div class="report__cell"><span class="report__key">Previous Lab No</span><span class="report__val"><?= e($r['previous_lab_no']) ?></span></div>
    <div class="report__cell"><span class="report__key">Clinician</span><span class="report__val"><?= e($r['clinician']) ?></span></div>
</div>

<?php
$sections = [
    'Clinical History'      => $r['clinical_history'],
    'Provisional Diagnosis' => $r['provisional_diagnosis'],
    'Nature of Specimen'    => $r['nature_of_specimen'],
    'Gross'                 => $r['gross'],
    'Microscopy'            => $r['microscopy'],
    'Further Tests'         => $r['further_tests'],
    'Bone Marrow'           => $r['bone_marrow'],
    'Lymphomas'             => $r['lymphomas'],
    'Diagnosis'             => $r['diagnosis'],
    'Remarks'               => $r['remarks'],
];
foreach ($sections as $heading => $text):
?>
<div class="report__section">
    <h3><?= e($heading) ?></h3>
    <div class="report__prose"><?= e($text) ?></div>
</div>
<?php endforeach; ?>

<div class="report__sign">
    <div><span class="report__key">Resident Doctor(s)</span><span class="report__val"><?= e($r['resident_doctors']) ?></span></div>
    <div><span class="report__key">Consultant Pathologist(s)</span><span class="report__val"><?= e($r['consultant_pathologists']) ?></span></div>
    <div><span class="report__key">Date Out</span><span class="report__val"><?= e($r['date_out']) ?></span></div>
    <div><span class="report__key">Adverse Incidents</span><span class="report__val"><?= e($r['adverse_incidents']) ?></span></div>
    <div><span class="report__key">Cost</span><span class="report__val"><?= e($r['cost']) ?></span></div>
    <div><span class="report__key">Status</span><span class="report__val"><?= ucfirst(e($r['status'])) ?></span></div>
</div>
