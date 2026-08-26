<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$childrenParams = [];

$childrenScope = nutritionist_scope_fragment(
    $user,
    'c.barangay_id',
    $childrenParams
);

$children = admin_fetch_all(
    "SELECT
        c.id,
        c.child_code,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.birthdate,
        c.sex,
        bg.name AS barangay
     FROM children c
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     WHERE {$childrenScope}
     ORDER BY
        c.last_name ASC,
        c.first_name ASC",
    str_repeat('i', count($childrenParams)),
    $childrenParams
);

$preselectedChildId = (int)($_GET['child'] ?? 0);

$actions = '<a class="admin-btn-secondary" href="'
    . nutritionist_e(app_url('/nutritionist/children.php'))
    . '">Back to children</a>';

nutritionist_layout_start(
    'Record Manual Measurement',
    'Fallback when the kiosk is unavailable. WHO z-scores are computed and saved automatically.',
    'children',
    $actions
);

?>

<section class="nutritionist-panel">

    <div
        class="nutritionist-form-head"
        style="margin-bottom:16px;"
    >

        <div>

            <h2
                class="admin-section-title"
                style="margin-bottom:2px;"
            >
                Record Manual Measurement
            </h2>

            <p class="admin-section-subtitle">
                Weight in kg, height in cm &mdash; results appear immediately after saving.
            </p>

        </div>

    </div>


    <form
        method="post"
        id="manual-measurement-form"
        class="nutritionist-form-grid"
        data-endpoint="<?php echo nutritionist_e(
            app_url('/api/nutritionist/measurements_create.php')
        ); ?>"
        data-children-url="<?php echo nutritionist_e(
            app_url('/nutritionist/children.php')
        ); ?>"
    >

        <label class="admin-field">

            <span>
                Child
                <span class="admin-required">*</span>
            </span>

            <select
                name="child_id"
                id="manual-measurement-child"
                required
            >

                <option value="">Select a child...</option>

                <?php foreach ($children as $option): ?>

                    <option
                        value="<?php echo (int)$option['id']; ?>"
                        <?php echo (
                            $preselectedChildId > 0
                            && (int)$option['id'] === $preselectedChildId
                        )
                            ? 'selected'
                            : ''; ?>
                    >

                        <?php
                        echo nutritionist_e(
                            trim(
                                $option['first_name']
                                . ' '
                                . ($option['middle_name'] ?? '')
                                . ' '
                                . $option['last_name']
                            )
                            . ' (' . $option['child_code']
                            . ' · ' . ($option['barangay'] ?? 'No barangay') . ')'
                        );
                        ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </label>


        <label class="admin-field">

            <span>
                Measurement date
                <span class="admin-required">*</span>
            </span>

            <input
                type="date"
                name="measurement_date"
                id="manual-measurement-date"
                value="<?php echo nutritionist_e(date('Y-m-d')); ?>"
                max="<?php echo nutritionist_e(date('Y-m-d')); ?>"
                required
            >

        </label>


        <label class="admin-field">

            <span>
                Weight (kg)
                <span class="admin-required">*</span>
            </span>

            <input
                type="number"
                name="weight_kg"
                id="manual-measurement-weight"
                step="0.001"
                min="2"
                max="80"
                inputmode="decimal"
                placeholder="e.g. 14.5"
                required
            >

            <span class="admin-field-message"></span>

        </label>


        <label class="admin-field">

            <span>
                Height (cm)
                <span class="admin-required">*</span>
            </span>

            <input
                type="number"
                name="height_cm"
                id="manual-measurement-height"
                step="0.01"
                min="40"
                max="140"
                inputmode="decimal"
                placeholder="e.g. 95.5"
                required
            >

            <span class="admin-field-message"></span>

        </label>


        <div
            class="admin-field"
            style="align-content:end;grid-column:1 / -1;"
        >

            <span>&nbsp;</span>

            <div class="admin-actions">

                <button
                    class="admin-btn"
                    type="submit"
                    id="manual-measurement-submit"
                >
                    Save measurement
                </button>

            </div>

        </div>

    </form>


    <div
        id="manual-measurement-result"
        style="display:none;margin-top:18px;"
    ></div>

</section>


<script>
(function () {
    var form = document.getElementById('manual-measurement-form');
    var resultBox = document.getElementById('manual-measurement-result');
    var submitBtn = document.getElementById('manual-measurement-submit');
    var dateInput = document.getElementById('manual-measurement-date');

    if (!form || !resultBox || !submitBtn) {
        return;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function signed(value) {
        var num = Number(value);
        return (num > 0 ? '+' : '') + num.toFixed(2);
    }

    function statusPillClass(status) {
        if (status === 'Normal') return 'is-success';
        if (status === 'Overweight') return 'is-warn';
        if (!status || status === 'Pending') return 'is-muted';
        return 'is-danger';
    }

    function zCardHtml(label, value, description) {
        var strong = Math.abs(Number(value)) > 2;
        return ''
            + '<div style="background:var(--admin-surface-alt);border-radius:12px;padding:16px;text-align:center;">'
            + '<div style="font-size:10px;color:var(--admin-muted);letter-spacing:0.5px;">' + escapeHtml(description) + '</div>'
            + '<div style="font-size:28px;font-weight:800;color:' + (strong ? 'var(--admin-danger)' : 'var(--admin-primary)') + ';margin:8px 0 4px;">'
            + escapeHtml(signed(value))
            + '</div>'
            + '<div style="font-size:10px;color:var(--admin-muted);">' + escapeHtml(label) + ' Z-Score</div>'
            + '</div>';
    }

    function renderResult(data) {
        var html = '';
        html += '<div style="font-weight:700;font-size:14px;color:var(--admin-text);margin-bottom:12px;">';
        html += 'Measurement Saved — ' + escapeHtml(data.child_name) + ' (' + escapeHtml(data.child_code) + ')';
        html += '</div>';

        html += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">';
        html += '<span class="admin-pill is-muted">Date: ' + escapeHtml(data.measurement_date) + '</span>';
        html += '<span class="admin-pill is-muted">' + escapeHtml(Number(data.weight_kg).toFixed(3)) + ' kg</span>';
        html += '<span class="admin-pill is-muted">' + escapeHtml(Number(data.height_cm).toFixed(2)) + ' cm</span>';
        html += '<span class="admin-pill is-muted">' + escapeHtml(String(data.age_months)) + ' months</span>';
        html += '<span class="admin-pill ' + statusPillClass(data.nutritional_status) + '">' + escapeHtml(String(data.nutritional_status || '—')) + '</span>';
        html += '</div>';

        html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">';
        html += zCardHtml('WAZ', data.waz, 'Weight-for-Age');
        html += zCardHtml('HAZ', data.haz, 'Height-for-Age');
        html += zCardHtml('WHZ', data.whz, 'Weight-for-Height');
        html += '</div>';

        html += '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">';
        html += '<span class="admin-pill is-muted">WFA: ' + escapeHtml(String(data.wfa_status || '—')) + '</span>';
        html += '<span class="admin-pill is-muted">HFA: ' + escapeHtml(String(data.hfa_status || '—')) + '</span>';
        html += '<span class="admin-pill is-muted">WFH: ' + escapeHtml(String(data.wfh_status || '—')) + '</span>';
        html += '</div>';

        if (data.is_flagged) {
            html += '<div style="margin-top:12px;padding:10px 12px;border-radius:8px;background:rgba(224,49,49,0.08);color:#E03131;font-size:12px;">';
            html += '⚠ Flagged for review' + (data.flag_reason ? ': ' + escapeHtml(String(data.flag_reason)) : '');
            html += '</div>';
        }

        html += '<div style="margin-top:14px;">';
        html += '<a class="admin-btn-secondary" href="' + escapeHtml(form.getAttribute('data-children-url') || '') + '?view=' + Number(data.child_id) + '">View child record</a>';
        html += '</div>';

        resultBox.innerHTML = html;
        resultBox.style.display = 'block';
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var originalLabel = submitBtn.textContent;

        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';

        var body = {
            child_id: form.elements.child_id.value,
            measurement_date: form.elements.measurement_date.value,
            weight_kg: form.elements.weight_kg.value,
            height_cm: form.elements.height_cm.value
        };

        fetch(form.getAttribute('data-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Unexpected server response.');
                });
            })
            .then(function (json) {
                if (!json.success) {
                    throw new Error(json.message || 'Could not save the measurement.');
                }

                renderResult(json.data);

                form.elements.weight_kg.value = '';
                form.elements.height_cm.value = '';

                if (dateInput) {
                    dateInput.value = new Date().toISOString().slice(0, 10);
                }

                resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(function (error) {
                resultBox.style.display = 'block';
                resultBox.innerHTML = '<div style="padding:12px;border-radius:8px;background:rgba(224,49,49,0.08);color:#E03131;font-size:13px;">'
                    + escapeHtml(error.message || 'Could not save the measurement.')
                    + '</div>';
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            });
    });
})();
</script>


<?php

nutritionist_layout_end();
