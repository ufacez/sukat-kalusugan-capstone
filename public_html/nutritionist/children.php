<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

function nutritionist_child_status_class(?string $status): string
{
    return match ($status) {
        'Normal', 'Tall' => 'is-success',
        'Moderately Underweight', 'Moderately Stunted', 'Moderately Wasted' => 'is-warn',
        'Overweight', 'Obese' => 'is-orange',
        'Pending' => 'is-muted',
        default => 'is-danger',
    };
}

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string)($_POST['action'] ?? '');
    $childId = (int)($_POST['id'] ?? 0);

    /*
     * DELETE
     */
    if ($action === 'delete' && $childId > 0) {

        if (admin_execute(
            'DELETE FROM children WHERE id = ?',
            'i',
            [$childId]
        )) {
            admin_redirect(
                '/nutritionist/children.php',
                ['notice' => 'Child removed successfully.']
            );
        }

        admin_redirect(
            '/nutritionist/children.php',
            [
                'notice' => 'Child could not be removed because of linked records.',
                'type' => 'error'
            ]
        );
    }
}

/*
 * FILTERS / VIEW
 */
$statusFilter = (string)($_GET['status'] ?? 'All');
$viewId = (int)($_GET['view'] ?? 0);
$deleteId = (int)($_GET['delete'] ?? 0);

if ($deleteId > 0) {
    admin_redirect(
        '/nutritionist/child_form.php?id=' . $deleteId
    );
}


/*
 * CHILDREN
 */
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
        c.barangay_id,
        bg.name AS barangay,
        c.is_ip,
        c.has_disability,
        c.parent_id,

        p.name AS parent_name,
        p.status AS parent_status,

        lm.measurement_date,
        lm.height_cm,
        lm.weight_kg,
        lm.waz,
        lm.haz,
        lm.whz,
        lm.nutritional_status,
        lm.wfa_status,
        lm.hfa_status,
        lm.wfh_status

     FROM children c

     INNER JOIN parents p
        ON p.id = c.parent_id

     LEFT JOIN barangays bg
        ON bg.id = c.barangay_id

     LEFT JOIN measurements lm
        ON lm.id = (
            SELECT m.id
            FROM measurements m
            WHERE m.child_id = c.id
            ORDER BY m.measurement_date DESC, m.id DESC
            LIMIT 1
        )

     WHERE {$childrenScope}

     ORDER BY
        c.last_name ASC,
        c.first_name ASC",
    str_repeat('i', count($childrenParams)),
    $childrenParams
);

$viewChild = null;

foreach ($children as $child) {

    if ((int)$child['id'] === $viewId) {
        $viewChild = $child;
    }
}


/*
 * PARENTS
 *
 * barangay_id is included because the selected parent's
 * Barangay is used automatically for the child.
 */
$parents = admin_fetch_all(
    "SELECT
        p.id,
        p.name,
        p.parent_type,
        p.status,
        p.phone,
        p.address,
        p.barangay_id,
        bg.name AS barangay
     FROM parents p
     LEFT JOIN barangays bg
        ON bg.id = p.barangay_id
     ORDER BY p.name ASC",
    '',
    []
);


/*
 * STATUS FILTER
 */
$statuses = [
    'All',
    'Normal',
    'Underweight',
    'Severely Underweight',
    'Stunted',
    'Severely Stunted',
    'Moderately Wasted',
    'Severely Wasted',
    'Overweight',
    'Obese'
];

$filteredChildren = array_values(
    array_filter(
        $children,
        static function (array $child) use ($statusFilter): bool {

            if ($statusFilter === 'All') {
                return true;
            }

            return (string)(
                $child['nutritional_status'] ?? 'Pending'
            ) === $statusFilter;
        }
    )
);


/*
 * SELECTED CHILD
 */
$selectedChild = $viewChild;

$selectedMeasurements = [];

if ($selectedChild !== null) {

    $measurementParams = [
        (int)$selectedChild['id']
    ];

    $selectedMeasurements = admin_fetch_all(
        'SELECT
            id,
            measurement_date,
            height_cm,
            weight_kg,
            waz,
            haz,
            whz,
            nutritional_status,
            source_type
         FROM measurements
         WHERE child_id = ?
         ORDER BY measurement_date DESC, id DESC',
        'i',
        $measurementParams
    );

    $openFollowup = admin_fetch_one(
        "SELECT id, scheduled_at, status, followup_track, followup_category
         FROM appointments
         WHERE child_id = ?
           AND appointment_type = 'followup'
           AND status IN ('pending', 'confirmed')
         ORDER BY scheduled_at ASC
         LIMIT 1",
        'i',
        [(int)$selectedChild['id']]
    );
}


$actions = '<div class="admin-actions">'
    . '<a class="admin-btn-secondary" href="'
    . nutritionist_e(app_url('/nutritionist/measurement_record.php'))
    . '">Record measurement</a>'
    . '<a class="admin-btn" href="'
    . nutritionist_e(app_url('/nutritionist/child_form.php'))
    . '">Add child</a>'
    . '</div>';

nutritionist_layout_start(
    'Children & Growth',
    'Registered children, latest growth status, and follow-up history.',
    'children',
    $actions
);

?>

<section class="nutritionist-panel">

    <div class="nutritionist-form-head" style="margin-bottom:12px;">

        <div>
            <h2 class="admin-section-title" style="margin-bottom:2px;">
                Children Monitoring
            </h2>

            <p class="admin-section-subtitle">
                <?php echo count($children); ?> registered children
            </p>
        </div>

        <div class="nutritionist-chip-row">

            <?php foreach ($statuses as $status): ?>

                <a
                    class="nutritionist-chip<?php echo $statusFilter === $status ? ' is-active' : ''; ?>"
                    href="<?php echo nutritionist_e(
                        app_url(
                            '/nutritionist/children.php?status='
                            . urlencode($status)
                        )
                    ); ?>"
                >
                    <?php echo nutritionist_e($status); ?>
                </a>

            <?php endforeach; ?>

        </div>

    </div>


    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">

        <input
            class="admin-search"
            data-admin-filter="#children-table"
            type="search"
            placeholder="Search children..."
            style="flex:1;min-width:200px;"
        >

    </div>


    <div class="nutritionist-table-wrap">

        <table class="nutritionist-table" id="children-table">

            <thead>

                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Age</th>
                    <th>Sex</th>
                    <th>Barangay</th>
                    <th>Parent</th>
                    <th>Status</th>
                    <th>Next Due</th>
                    <th>Actions</th>
                </tr>

            </thead>

            <tbody>

                <?php foreach ($filteredChildren as $child): ?>

                    <?php

                    $ageMonths =
                        doh_age_in_months(
                            (string)$child['birthdate']
                        ) ?? 0;

                    $status =
                        (string)(
                            $child['nutritional_status']
                            ?? 'Pending'
                        );

                    $pillClass =
                        nutritionist_child_status_class($status);

                    $schedule = followup_card_state(
                        (string)$child['birthdate'],
                        ($child['measurement_date'] ?? null) !== null ? (string)$child['measurement_date'] : null,
                        ($child['wfa_status'] ?? null) !== null ? (string)$child['wfa_status'] : null,
                        ($child['hfa_status'] ?? null) !== null ? (string)$child['hfa_status'] : null,
                        ($child['wfh_status'] ?? null) !== null ? (string)$child['wfh_status'] : null
                    );

                    ?>

                    <tr
                        data-filter-text="<?php
                            echo nutritionist_e(
                                strtolower(
                                    $child['child_code']
                                    . ' '
                                    . $child['first_name']
                                    . ' '
                                    . ($child['middle_name'] ?? '')
                                    . ' '
                                    . $child['last_name']
                                    . ' '
                                    . (string)($child['barangay'] ?? '')
                                    . ' '
                                    . $child['parent_name']
                                    . ' '
                                    . $status
                                    . ' '
                                    . $schedule['label']
                                )
                            );
                        ?>"
                    >

                        <td style="font-family:monospace;color:var(--admin-muted);">
                            <?php echo nutritionist_e($child['child_code']); ?>
                        </td>

                        <td>

                            <div style="display:flex;align-items:center;gap:8px;">

                                <div
                                    class="admin-pill <?php echo $pillClass; ?>"
                                    style="min-width:30px;justify-content:center;border-radius:50%;padding:0.35rem 0.5rem;"
                                >
                                    <?php
                                    echo nutritionist_e(
                                        substr($child['first_name'], 0, 1)
                                        . substr($child['last_name'], 0, 1)
                                    );
                                    ?>
                                </div>

                                <div>

                                    <div
                                        style="font-size:13px;font-weight:600;color:var(--admin-text);"
                                    >
                                        <?php
                                        echo nutritionist_e(
                                            trim(
                                                $child['first_name']
                                                . ' '
                                                . ($child['middle_name'] ?? '')
                                                . ' '
                                                . $child['last_name']
                                            )
                                        );
                                        ?>
                                    </div>

                                    <div
                                        style="font-size:10px;color:var(--admin-muted);margin-top:1px;"
                                    >
                                        <?php
                                        echo nutritionist_e(
                                            (string)$child['birthdate']
                                        );
                                        ?>
                                    </div>

                                </div>

                            </div>

                        </td>

                        <td style="color:var(--admin-muted);">
                            <?php echo (int)$ageMonths; ?> mo
                        </td>

                        <td style="color:var(--admin-muted);">
                            <?php echo nutritionist_e(
                                (string)$child['sex']
                            ); ?>
                        </td>

                        <td style="color:var(--admin-muted);">
                            <?php echo nutritionist_e(
                                (string)($child['barangay'] ?? '')
                            ); ?>
                        </td>

                        <td style="color:var(--admin-muted);">
                            <?php echo nutritionist_e(
                                (string)$child['parent_name']
                            ); ?>
                        </td>

                        <td>
                            <span class="admin-pill <?php echo $pillClass; ?>">
                                <?php echo nutritionist_e($status); ?>
                            </span>
                        </td>

                        <td>

                            <div
                                style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;"
                            >

                                <span
                                    class="admin-pill <?php echo $schedule['class']; ?>"
                                    title="EOPT schedule compliance flag"
                                >
                                    <?php echo nutritionist_e($schedule['label']); ?>
                                </span>

                                <span style="font-size:10px;color:var(--admin-muted);">
                                    <?php
                                    echo $schedule['due'] !== null
                                        ? nutritionist_e('Next: ' . $schedule['due'])
                                        : '—';
                                    ?>
                                </span>

                            </div>

                        </td>

                        <td>

                            <div class="admin-actions">

                                <a
                                    class="admin-btn-secondary"
                                    href="<?php echo nutritionist_e(
                                        app_url(
                                            '/nutritionist/measurement_record.php?child='
                                            . (int)$child['id']
                                        )
                                    ); ?>"
                                    title="Record a manual measurement for this child"
                                >
                                    Measure
                                </a>

                                <a
                                    class="admin-btn-secondary"
                                    href="<?php echo nutritionist_e(
                                        app_url(
                                            '/nutritionist/children.php?view='
                                            . (int)$child['id']
                                        )
                                    ); ?>"
                                >
                                    View
                                </a>

                                <a
                                    class="admin-btn-secondary"
                                    href="<?php echo nutritionist_e(
                                        app_url(
                                            '/nutritionist/child_form.php?id='
                                            . (int)$child['id']
                                        )
                                    ); ?>"
                                >
                                    Edit
                                </a>

                                <form
                                    method="post"
                                    action="<?php echo nutritionist_e(
                                        app_url('/nutritionist/children.php')
                                    ); ?>"
                                    onsubmit="return confirm('Delete <?php
                                        echo nutritionist_e(
                                            trim(
                                                $child['first_name']
                                                . ' '
                                                . ($child['middle_name'] ?? '')
                                                . ' '
                                                . $child['last_name']
                                            )
                                        );
                                    ?>?');"
                                >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?php echo (int)$child['id']; ?>"
                                    >

                                    <button
                                        class="admin-btn-danger"
                                        type="submit"
                                    >
                                        Delete
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</section>


<?php if ($selectedChild !== null): ?>

<section
    class="nutritionist-panel-grid"
    style="margin-top:18px;"
>

    <article class="nutritionist-panel">

        <div style="text-align:center;margin-bottom:20px;">

            <div
                style="display:flex;justify-content:center;margin-bottom:12px;"
            >

                <div
                    class="admin-pill <?php
                        echo nutritionist_child_status_class(
                            (string)(
                                $selectedChild['nutritional_status']
                                ?? 'Pending'
                            )
                        );
                    ?>"
                    style="width:64px;height:64px;border-radius:50%;font-size:1rem;justify-content:center;"
                >
                    <?php
                    echo nutritionist_e(
                        substr(
                            (string)$selectedChild['first_name'],
                            0,
                            1
                        )
                        . substr(
                            (string)$selectedChild['last_name'],
                            0,
                            1
                        )
                    );
                    ?>
                </div>

            </div>

            <h2
                style="margin:0;font-size:16px;font-weight:700;color:var(--admin-text);"
            >
                <?php
                echo nutritionist_e(
                    trim(
                        $selectedChild['first_name']
                        . ' '
                        . ($selectedChild['middle_name'] ?? '')
                        . ' '
                        . $selectedChild['last_name']
                    )
                );
                ?>
            </h2>

            <div
                style="color:var(--admin-muted);font-size:12px;margin:4px 0 10px;"
            >
                <?php
                echo nutritionist_e(
                    (string)$selectedChild['child_code']
                );
                ?>
            </div>

            <span
                class="admin-pill <?php
                    echo nutritionist_child_status_class(
                        (string)(
                            $selectedChild['nutritional_status']
                            ?? 'Pending'
                        )
                    );
                ?>"
            >
                <?php
                echo nutritionist_e(
                    (string)(
                        $selectedChild['nutritional_status']
                        ?? 'Pending'
                    )
                );
                ?>
            </span>

        </div>


        <div
            style="border-top:1px solid var(--admin-border);padding-top:16px;"
        >

            <?php foreach ([

                [
                    'Birthdate',
                    $selectedChild['birthdate']
                ],

                [
                    'Age',
                    (
                        doh_age_in_months(
                            (string)$selectedChild['birthdate']
                        ) ?? 0
                    ) . ' months'
                ],

                [
                    'Sex',
                    $selectedChild['sex']
                ],

                [
                    'Barangay',
                    $selectedChild['barangay']
                ],

                [
                    'Parent',
                    $selectedChild['parent_name']
                ],

                [
                    'IP Group',
                    !empty($selectedChild['is_ip'])
                        ? 'Yes'
                        : 'No'
                ],

                [
                    'Disability',
                    !empty($selectedChild['has_disability'])
                        ? 'Yes'
                        : 'No'
                ],

            ] as [$label, $value]): ?>

                <div
                    style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--admin-border);"
                >

                    <span
                        style="font-size:12px;color:var(--admin-muted);"
                    >
                        <?php echo nutritionist_e($label); ?>
                    </span>

                    <span
                        style="font-size:12px;font-weight:600;color:var(--admin-text);text-align:right;max-width:55%;"
                    >
                        <?php echo nutritionist_e((string)$value); ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>


        <?php if (
            ($selectedChild['wfa_status'] ?? null) !== null
            || ($selectedChild['hfa_status'] ?? null) !== null
            || ($selectedChild['wfh_status'] ?? null) !== null
        ): ?>

            <div
                style="margin-top:14px;padding-top:14px;border-top:1px solid var(--admin-border);"
            >

                <div
                    style="font-weight:700;font-size:12px;color:var(--admin-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.04em;"
                >
                    DOH Nutritional Status (per OPT Plus)
                </div>

                <div
                    style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;"
                >

                    <?php foreach ([

                        [
                            'WFA',
                            $selectedChild['wfa_status'] ?? '—'
                        ],

                        [
                            'HFA',
                            $selectedChild['hfa_status'] ?? '—'
                        ],

                        [
                            'WFH',
                            $selectedChild['wfh_status'] ?? '—'
                        ],

                    ] as [$axis, $val]): ?>

                        <div
                            style="text-align:center;background:var(--admin-surface-alt,#f7f7f5);border-radius:8px;padding:8px 4px;"
                        >

                            <div
                                style="font-size:10px;color:var(--admin-muted);"
                            >
                                <?php echo nutritionist_e($axis); ?>
                            </div>

                            <div
                                style="font-size:12px;font-weight:700;color:var(--admin-text);"
                            >
                                <?php echo nutritionist_e((string)$val); ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endif; ?>

        <?php

        $selectedSchedule = followup_card_state(
            (string)$selectedChild['birthdate'],
            ($selectedChild['measurement_date'] ?? null) !== null ? (string)$selectedChild['measurement_date'] : null,
            ($selectedChild['wfa_status'] ?? null) !== null ? (string)$selectedChild['wfa_status'] : null,
            ($selectedChild['hfa_status'] ?? null) !== null ? (string)$selectedChild['hfa_status'] : null,
            ($selectedChild['wfh_status'] ?? null) !== null ? (string)$selectedChild['wfh_status'] : null
        );

        ?>

        <div
            style="margin-top:14px;padding-top:14px;border-top:1px solid var(--admin-border);"
        >

            <div
                style="font-weight:700;font-size:12px;color:var(--admin-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.04em;"
            >
                EOPT Follow-up Schedule
            </div>

            <div
                style="display:flex;justify-content:space-between;align-items:center;gap:8px;background:var(--admin-surface-alt,#f7f7f5);border-radius:8px;padding:10px 12px;margin-bottom:8px;"
            >

                <span style="font-size:12px;color:var(--admin-muted);">
                    Next measurement due
                </span>

                <span style="font-size:12px;font-weight:700;color:var(--admin-text);">
                    <?php echo $selectedSchedule['due'] !== null ? nutritionist_e($selectedSchedule['due']) : '—'; ?>
                </span>

            </div>

            <div
                style="display:flex;justify-content:space-between;align-items:center;gap:8px;"
            >

                <span class="admin-pill <?php echo $selectedSchedule['class']; ?>">
                    <?php echo nutritionist_e($selectedSchedule['label']); ?>
                </span>

                <?php if ($openFollowup !== null): ?>
                    <span class="admin-mini">
                        Follow-up booked:
                        <?php echo nutritionist_e(date('M j, Y', strtotime((string)$openFollowup['scheduled_at']))); ?>
                        · <?php echo nutritionist_e($openFollowup['followup_track'] === 'quarterly' ? 'Quarterly' : 'Monthly'); ?>
                    </span>
                <?php endif; ?>

            </div>

        </div>

    </article>


    <article class="nutritionist-panel">

        <?php if (!empty($selectedMeasurements)): ?>

            <div
                style="font-weight:700;font-size:14px;color:var(--admin-text);margin-bottom:14px;"
            >
                Latest WHO Z-Scores
            </div>

            <div
                style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;"
            >

                <?php foreach ([

                    [
                        'WAZ',
                        $selectedMeasurements[0]['waz'],
                        'Weight-for-Age'
                    ],

                    [
                        'HAZ',
                        $selectedMeasurements[0]['haz'],
                        'Height-for-Age'
                    ],

                    [
                        'WHZ',
                        $selectedMeasurements[0]['whz'],
                        'Weight-for-Height'
                    ],

                ] as [$label, $value, $description]): ?>

                    <div
                        style="background:var(--admin-surface-alt);border-radius:12px;padding:16px;text-align:center;"
                    >

                        <div
                            style="font-size:10px;color:var(--admin-muted);letter-spacing:0.5px;"
                        >
                            <?php echo nutritionist_e($description); ?>
                        </div>

                        <div
                            style="font-size:28px;font-weight:800;color:<?php
                                echo abs((float)$value) > 2
                                    ? 'var(--admin-danger)'
                                    : 'var(--admin-primary)';
                            ?>;margin:8px 0 4px;"
                        >
                            <?php
                            echo ((float)$value > 0 ? '+' : '')
                                . nutritionist_e((string)$value);
                            ?>
                        </div>

                        <div
                            style="font-size:10px;color:var(--admin-muted);"
                        >
                            <?php echo nutritionist_e($label); ?> Z-Score
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>


            <div
                style="margin-top:12px;background:<?php
                    echo nutritionist_child_status_class(
                        (string)($selectedChild['nutritional_status'] ?? 'Pending')
                    ) === 'is-success'
                        ? 'var(--admin-primary-soft)'
                        : 'var(--admin-surface-alt)';
                ?>;border:1px solid var(--admin-border);border-radius:10px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;"
            >

                <span
                    style="font-size:12px;font-weight:700;color:var(--admin-text);"
                >
                    Nutritional Status:
                    <?php
                    echo nutritionist_e(
                        (string)(
                            $selectedChild['nutritional_status']
                            ?? 'Pending'
                        )
                    );
                    ?>
                </span>

                <span
                    style="font-size:11px;color:var(--admin-muted);"
                >
                    H:
                    <?php
                    echo nutritionist_e(
                        (string)($selectedChild['height_cm'] ?? 'n/a')
                    );
                    ?>cm · W:
                    <?php
                    echo nutritionist_e(
                        (string)($selectedChild['weight_kg'] ?? 'n/a')
                    );
                    ?>kg
                </span>

            </div>

        <?php endif; ?>


        <div
            style="font-weight:700;font-size:14px;color:var(--admin-text);margin:18px 0 14px;"
        >
            Measurement History
        </div>


        <?php if ($selectedMeasurements === []): ?>

            <div
                style="text-align:center;color:var(--admin-muted);font-size:13px;padding:20px;"
            >
                No measurements recorded
            </div>

        <?php else: ?>

            <table
                class="nutritionist-table"
                id="measurement-history-table"
                data-child-id="<?php echo (int)$selectedChild['id']; ?>"
            >

                <thead>

                    <tr>
                        <th>Date</th>
                        <th>Height</th>
                        <th>Weight</th>
                        <th>WAZ</th>
                        <th>HAZ</th>
                        <th>WHZ</th>
                        <th>Status</th>
                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($selectedMeasurements as $measurement): ?>

                        <tr>

                            <td>
                                <?php
                                echo nutritionist_e(
                                    (string)$measurement['measurement_date']
                                );

                                if (
                                    ($measurement['source_type'] ?? 'kiosk')
                                    === 'manual'
                                ):
                                ?>

                                    <span
                                        class="admin-pill is-muted"
                                        style="margin-left:6px;font-size:9px;padding:1px 6px;vertical-align:middle;"
                                        title="Recorded manually by staff"
                                    >
                                        Manual
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td style="color:var(--admin-muted);">
                                <?php
                                echo nutritionist_e(
                                    (string)$measurement['height_cm']
                                );
                                ?>
                            </td>

                            <td style="color:var(--admin-muted);">
                                <?php
                                echo nutritionist_e(
                                    (string)$measurement['weight_kg']
                                );
                                ?>
                            </td>

                            <td
                                style="color:var(--admin-primary);font-weight:600;"
                            >
                                <?php
                                echo ((float)$measurement['waz'] > 0 ? '+' : '')
                                    . nutritionist_e(
                                        (string)$measurement['waz']
                                    );
                                ?>
                            </td>

                            <td
                                style="color:var(--admin-info,#4a9fd5);font-weight:600;"
                            >
                                <?php
                                echo ((float)$measurement['haz'] > 0 ? '+' : '')
                                    . nutritionist_e(
                                        (string)$measurement['haz']
                                    );
                                ?>
                            </td>

                            <td
                                style="color:#0d8871;font-weight:600;"
                            >
                                <?php
                                echo ((float)$measurement['whz'] > 0 ? '+' : '')
                                    . nutritionist_e(
                                        (string)$measurement['whz']
                                    );
                                ?>
                            </td>

                            <td>

                                <span
                                    class="admin-pill <?php
                                        echo nutritionist_child_status_class(
                                            (string)$measurement['nutritional_status']
                                        );
                                    ?>"
                                >
                                    <?php
                                    echo nutritionist_e(
                                        (string)$measurement['nutritional_status']
                                    );
                                    ?>
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </article>

</section>

<?php endif; ?>

<?php

nutritionist_layout_end();
