<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/_form_support.php';
require_login();
require_role(['staff', 'admin', 'reviewer']);

const CYTOLOGY_FIELDS = [
    'lab_no', 'surname', 'other_names', 'age', 'sex', 'ethnic_group', 'requesting_hospital',
    'hosp_no', 'ward_clinic', 'patients_tel_no', 'date_of_collection', 'clinical_history',
    'lmp', 'drug_history', 'radiation', 'previous_lab_no', 'nature_of_specimen', 'clinician',
    'clinician_tel_no', 'microscopy', 'diagnosis', 'recommendation', 'resident_doctors',
    'consultant_pathologists', 'signout_date', 'cost',
];
const CYTOLOGY_DATE_FIELDS = ['date_of_collection', 'lmp', 'signout_date'];

$table = 'cytology_reports';
$id = (int)($_GET['id'] ?? $_POST['record_id'] ?? 0);
$editing = $id > 0;
$errors = [];

if (!$editing && current_role() === 'reviewer') {
    http_response_code(403);
    die("Reviewers can edit existing reports but cannot create new ones.");
}

$record = $editing ? load_editable_report($pdo, $table, $id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = collect_post_fields(CYTOLOGY_FIELDS);
    $errors = validate_report($data, CYTOLOGY_DATE_FIELDS);

    if (!$errors && lab_no_taken($pdo, $table, $data['lab_no'], $id)) {
        $errors[] = "Lab No '{$data['lab_no']}' is already used by another cytology report.";
    }

    if (!$errors) {
        try {
            if ($editing) {
                update_report($pdo, $table, $id, $data);
                log_action($pdo, current_user_id(), 'update_record', $table, $id, "Lab No {$data['lab_no']} - resubmitted for review");
            } else {
                $id = insert_report($pdo, $table, $data, current_user_id());
                log_action($pdo, current_user_id(), 'create_record', $table, $id, "Lab No {$data['lab_no']}");
            }
            redirect('records/cytology_print.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = "The report could not be saved. Check the values and try again.";
            error_log('cytology_form save failed: ' . $e->getMessage());
        }
    }
}

// What to show in the inputs: submitted values on a failed save, otherwise the
// stored record when editing, otherwise blanks.
$form = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : ($record ?? []);

/** Current value of a form field, escaped for output. */
function fv(string $field): string {
    global $form;
    return e((string)($form[$field] ?? ''));
}

render_header([
    'title'   => $editing ? 'Edit Cytology Report' : 'New Cytology Report',
    'heading' => $editing ? 'Edit cytology report' : 'New cytology report',
    'lead'    => $editing
        ? 'Lab number ' . e((string)($record['lab_no'] ?? '')) . ' &middot; ' . status_badge($record['status'])
        : 'Fields marked with an asterisk are required.',
    'nav'     => 'new-cytology',
    'back'    => ['label' => 'Back to records', 'url' => 'records/list.php'],
]);
?>

<?php if ($editing && $record['status'] === 'rejected' && !empty($record['reviewer_comments'])): ?>
    <div class="alert alert--error">
        <strong>Returned by reviewer:</strong><br><?= nl2br(e($record['reviewer_comments'])) ?>
    </div>
<?php endif; ?>

<?php if ($editing): ?>
    <div class="alert alert--info">
        Saving returns this report to <strong>pending</strong> and clears any previous review
        decision, so the updated findings get signed off again.
    </div>
<?php endif; ?>

<?php render_errors($errors); ?>

<form method="POST">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="record_id" value="<?= (int)$id ?>"><?php endif; ?>

    <div class="section">
        <div class="section__head">Patient</div>
        <div class="section__body">
            <div class="grid">
                <div class="field">
                    <label class="label" for="lab_no">Lab No <span class="req">*</span></label>
                    <input class="input" id="lab_no" name="lab_no" required value="<?= fv('lab_no') ?>">
                </div>
                <div class="field">
                    <label class="label" for="surname">Surname <span class="req">*</span></label>
                    <input class="input" id="surname" name="surname" required value="<?= fv('surname') ?>">
                </div>
                <div class="field">
                    <label class="label" for="other_names">Other names</label>
                    <input class="input" id="other_names" name="other_names" value="<?= fv('other_names') ?>">
                </div>
                <div class="field">
                    <label class="label" for="age">Age</label>
                    <input class="input" type="number" id="age" name="age" min="0" max="150" value="<?= fv('age') ?>">
                </div>
                <div class="field">
                    <label class="label" for="sex">Sex</label>
                    <select class="select" id="sex" name="sex">
                        <option value="">Not recorded</option>
                        <option value="MALE" <?= fv('sex') === 'MALE' ? 'selected' : '' ?>>Male</option>
                        <option value="FEMALE" <?= fv('sex') === 'FEMALE' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="ethnic_group">Ethnic group</label>
                    <input class="input" id="ethnic_group" name="ethnic_group" value="<?= fv('ethnic_group') ?>">
                </div>
                <div class="field">
                    <label class="label" for="patients_tel_no">Patient's tel No</label>
                    <input class="input" id="patients_tel_no" name="patients_tel_no" value="<?= fv('patients_tel_no') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section__head">Request</div>
        <div class="section__body">
            <div class="grid">
                <div class="field">
                    <label class="label" for="hosp_no">Hosp No</label>
                    <input class="input" id="hosp_no" name="hosp_no" value="<?= fv('hosp_no') ?>">
                </div>
                <div class="field">
                    <label class="label" for="requesting_hospital">Requesting hospital</label>
                    <input class="input" id="requesting_hospital" name="requesting_hospital" value="<?= fv('requesting_hospital') ?>">
                </div>
                <div class="field">
                    <label class="label" for="ward_clinic">Ward / clinic</label>
                    <input class="input" id="ward_clinic" name="ward_clinic" value="<?= fv('ward_clinic') ?>">
                </div>
                <div class="field">
                    <label class="label" for="date_of_collection">Date of collection</label>
                    <input class="input" type="date" id="date_of_collection" name="date_of_collection" value="<?= fv('date_of_collection') ?>">
                </div>
                <div class="field">
                    <label class="label" for="lmp">LMP</label>
                    <input class="input" type="date" id="lmp" name="lmp" value="<?= fv('lmp') ?>">
                </div>
                <div class="field">
                    <label class="label" for="previous_lab_no">Previous lab No</label>
                    <input class="input" id="previous_lab_no" name="previous_lab_no" value="<?= fv('previous_lab_no') ?>">
                </div>
                <div class="field">
                    <label class="label" for="drug_history">Drug history</label>
                    <input class="input" id="drug_history" name="drug_history" value="<?= fv('drug_history') ?>">
                </div>
                <div class="field">
                    <label class="label" for="radiation">Radiation</label>
                    <input class="input" id="radiation" name="radiation" value="<?= fv('radiation') ?>">
                </div>
                <div class="field">
                    <label class="label" for="clinician">Clinician</label>
                    <input class="input" id="clinician" name="clinician" value="<?= fv('clinician') ?>">
                </div>
                <div class="field">
                    <label class="label" for="clinician_tel_no">Clinician's tel No</label>
                    <input class="input" id="clinician_tel_no" name="clinician_tel_no" value="<?= fv('clinician_tel_no') ?>">
                </div>
            </div>

            <div class="field">
                <label class="label" for="nature_of_specimen">Nature of specimen</label>
                <input class="input" id="nature_of_specimen" name="nature_of_specimen" value="<?= fv('nature_of_specimen') ?>">
            </div>
            <div class="field">
                <label class="label" for="clinical_history">Clinical history</label>
                <textarea class="textarea" id="clinical_history" name="clinical_history"><?= fv('clinical_history') ?></textarea>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section__head">Findings</div>
        <div class="section__body">
            <div class="field">
                <label class="label" for="microscopy">Microscopy</label>
                <textarea class="textarea textarea--tall" id="microscopy" name="microscopy"><?= fv('microscopy') ?></textarea>
            </div>
            <div class="field">
                <label class="label" for="diagnosis">Diagnosis</label>
                <textarea class="textarea textarea--tall" id="diagnosis" name="diagnosis"><?= fv('diagnosis') ?></textarea>
            </div>
            <div class="field">
                <label class="label" for="recommendation">Recommendation</label>
                <textarea class="textarea" id="recommendation" name="recommendation"><?= fv('recommendation') ?></textarea>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section__head">Sign-out</div>
        <div class="section__body">
            <div class="grid grid--2">
                <div class="field">
                    <label class="label" for="resident_doctors">Resident doctor(s)</label>
                    <input class="input" id="resident_doctors" name="resident_doctors" value="<?= fv('resident_doctors') ?>">
                </div>
                <div class="field">
                    <label class="label" for="consultant_pathologists">Consultant pathologist(s)</label>
                    <input class="input" id="consultant_pathologists" name="consultant_pathologists" value="<?= fv('consultant_pathologists') ?>">
                </div>
            </div>
            <div class="grid">
                <div class="field">
                    <label class="label" for="signout_date">Signout date</label>
                    <input class="input" type="date" id="signout_date" name="signout_date" value="<?= fv('signout_date') ?>">
                </div>
                <div class="field">
                    <label class="label" for="cost">Cost</label>
                    <input class="input" type="number" step="0.01" min="0" id="cost" name="cost" value="<?= fv('cost') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit">
            <?= $editing ? 'Save changes and resubmit' : 'Save report' ?>
        </button>
        <a class="btn btn--ghost" href="<?= app_url('records/list.php') ?>">Cancel</a>
    </div>
</form>

<?php render_footer(); ?>
