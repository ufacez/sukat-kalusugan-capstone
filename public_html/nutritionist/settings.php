<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

function nutritionist_calendar_redirect_params(): array
{
	$params = [];
	$calMonth = $_SERVER['REQUEST_METHOD'] === 'POST'
		? ($_POST['cal_month'] ?? '')
		: ($_GET['cal_month'] ?? '');

	if ($calMonth !== '') {
		$params['cal_month'] = (string)$calMonth;
	}

	return $params;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$formAction = (string)($_POST['action'] ?? 'update_profile');

	if ($formAction === 'update_profile') {
		$name = trim((string)($_POST['name'] ?? ''));
		$email = trim((string)($_POST['email'] ?? ''));
		$phone = trim((string)($_POST['phone'] ?? ''));
		$barangayIdRaw = trim((string)($_POST['barangay_id'] ?? ''));
		$barangayId = $barangayIdRaw !== '' ? (int)$barangayIdRaw : null;
		$password = (string)($_POST['password'] ?? '');

		if ($name === '' || $email === '') {
			admin_redirect('/nutritionist/settings.php', ['notice' => 'Name and email are required.', 'type' => 'error']);
		}

		$current = admin_fetch_one('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1', 'i', [(int)$user['id']]);

		if ($current === null) {
			admin_redirect('/nutritionist/settings.php', ['notice' => 'Profile could not be loaded.', 'type' => 'error']);
		}

		$params = [$name, $email, $phone, $barangayId, (int)$user['id']];
		$sql = 'UPDATE users SET name = ?, email = ?, phone = ?, barangay_id = ?';
		$types = 'sssii';

		if ($password !== '') {
			$sql .= ', password_hash = ?';
			$types = 'sssisi';
			$params = [$name, $email, $phone, $barangayId, password_hash($password, PASSWORD_DEFAULT), (int)$user['id']];
		}

		$sql .= ' WHERE id = ?';

		$ok = admin_execute($sql, $types, $params);

		if ($ok) {
			$_SESSION['auth']['name'] = $name;
			$_SESSION['auth']['email'] = $email;
			$_SESSION['auth']['phone'] = $phone;
			$_SESSION['auth']['barangay_id'] = $barangayId;
			$barangayRow = $barangayId !== null ? admin_fetch_one('SELECT name FROM barangays WHERE id = ? LIMIT 1', 'i', [$barangayId]) : null;
			$_SESSION['auth']['barangay'] = $barangayRow['name'] ?? null;
		}

		admin_redirect('/nutritionist/settings.php', $ok ? ['notice' => 'Profile updated successfully.'] : ['notice' => 'Profile could not be updated.', 'type' => 'error']);
	}

	$redirectParams = nutritionist_calendar_redirect_params();

	if (in_array($formAction, ['create_event', 'update_event'], true)) {
		$eventId = (int)($_POST['id'] ?? 0);
		$eventType = (string)($_POST['event_type'] ?? '');
		$title = trim((string)($_POST['title'] ?? ''));
		$eventDate = trim((string)($_POST['event_date'] ?? ''));
		$eventTime = trim((string)($_POST['event_time'] ?? ''));
		$location = trim((string)($_POST['location'] ?? ''));
		$notes = trim((string)($_POST['notes'] ?? ''));
		$barangayId = $user['barangay_id'] ?? null;

		if (!in_array($eventType, ['meeting', 'oplan_timbang'], true) || $title === '' || $eventDate === '') {
			admin_redirect('/nutritionist/settings.php', $redirectParams + ['notice' => 'Event type, title, and date are required.', 'type' => 'error']);
		}

		$eventTimeValue = $eventTime !== '' ? $eventTime : null;
		$locationValue = $location !== '' ? $location : null;
		$notesValue = $notes !== '' ? $notes : null;

		if ($formAction === 'create_event') {
			$ok = admin_execute(
				'INSERT INTO nutritionist_events (event_type, title, event_date, event_time, location, barangay_id, notes, nutritionist_id)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
				'sssssisi',
				[$eventType, $title, $eventDate, $eventTimeValue, $locationValue, $barangayId, $notesValue, (int)$user['id']]
			);

			admin_redirect('/nutritionist/settings.php', $redirectParams + ($ok ? ['notice' => 'Event added to the calendar.'] : ['notice' => 'Event could not be added.', 'type' => 'error']));
		}

		if ($formAction === 'update_event' && $eventId > 0) {
			$updateParams = [$eventType, $title, $eventDate, $eventTimeValue, $locationValue, $notesValue, $eventId];
			$updateTypes = 'ssssssi';
			$ownerClause = nutritionist_scope_fragment($user, 'barangay_id', $updateParams);
			$updateTypes .= str_repeat('i', count($updateParams) - 7);

			$ok = admin_execute(
				"UPDATE nutritionist_events
				 SET event_type = ?, title = ?, event_date = ?, event_time = ?, location = ?, notes = ?
				 WHERE id = ? AND {$ownerClause}",
				$updateTypes,
				$updateParams
			);

			admin_redirect('/nutritionist/settings.php', $redirectParams + ($ok ? ['notice' => 'Event updated.'] : ['notice' => 'Event could not be updated.', 'type' => 'error']));
		}
	}

	if ($formAction === 'delete_event') {
		$eventId = (int)($_POST['id'] ?? 0);

		if ($eventId > 0) {
			$deleteParams = [$eventId];
			$ownerClause = nutritionist_scope_fragment($user, 'barangay_id', $deleteParams);
			$deleteTypes = 'i' . str_repeat('i', count($deleteParams) - 1);

			$ok = admin_execute("DELETE FROM nutritionist_events WHERE id = ? AND {$ownerClause}", $deleteTypes, $deleteParams);
			admin_redirect('/nutritionist/settings.php', $redirectParams + ($ok ? ['notice' => 'Event removed.'] : ['notice' => 'Event could not be removed.', 'type' => 'error']));
		}
	}
}

$calMonthParam = (string)($_GET['cal_month'] ?? '');
$calendarDate = DateTimeImmutable::createFromFormat('Y-m-d', $calMonthParam . '-01') ?: null;
$today = new DateTimeImmutable('today');

if ($calendarDate === false || $calendarDate === null || $calendarDate->format('Y-m') !== $calMonthParam) {
	$calendarDate = $today->modify('first day of this month');
	$calMonthParam = $calendarDate->format('Y-m');
} else {
	$calendarDate = $calendarDate->modify('first day of this month');
}

$prevCalMonthLink = app_url('/nutritionist/settings.php?' . http_build_query(['cal_month' => $calendarDate->modify('-1 month')->format('Y-m')]) . '#calendar-management');
$nextCalMonthLink = app_url('/nutritionist/settings.php?' . http_build_query(['cal_month' => $calendarDate->modify('+1 month')->format('Y-m')]) . '#calendar-management');
$calMonthStart = $calendarDate->format('Y-m-d');
$calMonthEnd = $calendarDate->modify('last day of this month')->format('Y-m-d');

$calEventsParams = [$calMonthStart, $calMonthEnd];
$calEventsScope = nutritionist_scope_fragment($user, 'ne.barangay_id', $calEventsParams);
$calMonthEvents = admin_fetch_all(
	"SELECT ne.id, ne.event_type, ne.title, ne.event_date, ne.event_time, ne.location, ne.notes, ne.nutritionist_id
	 FROM nutritionist_events ne
	 WHERE ne.event_date BETWEEN ? AND ? AND {$calEventsScope}
	 ORDER BY ne.event_date ASC, ne.event_time ASC, ne.id ASC",
	str_repeat('s', count($calEventsParams)),
	$calEventsParams
);

$editingEventId = (int)($_GET['edit_event'] ?? 0);
$editingEvent = null;

if ($editingEventId > 0) {
	foreach ($calMonthEvents as $eventRow) {
		if ((int)$eventRow['id'] === $editingEventId) {
			$editingEvent = $eventRow;
			break;
		}
	}
}

$profile = admin_fetch_one(
	'SELECT u.id, u.name, u.email, u.phone, u.barangay_id, b.name AS barangay, u.status, r.name AS role_name
	 FROM users u
	 INNER JOIN roles r ON r.id = u.role_id
	 LEFT JOIN barangays b ON b.id = u.barangay_id
	 WHERE u.id = ?
	 LIMIT 1',
	'i',
	[(int)$user['id']]
);

$barangays = admin_barangay_options();

$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/dashboard.php')) . '">Back to dashboard</a>';

nutritionist_layout_start('Settings', 'Manage your profile and account details.', 'settings', $actions);
?>
<section class="nutritionist-stat-grid">
	<article class="nutritionist-stat-card is-featured">
		<div class="nutritionist-stat-label">Account</div>
		<div class="admin-stat-value"><?php echo nutritionist_e(ucfirst((string)($profile['role_name'] ?? 'nutritionist'))); ?></div>
		<div class="admin-stat-note">Signed-in staff profile</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Status</div>
		<div class="admin-stat-value"><?php echo nutritionist_e(ucfirst((string)($profile['status'] ?? 'active'))); ?></div>
		<div class="admin-stat-note">Account access state</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Assigned Barangay</div>
		<div class="admin-stat-value"><?php echo nutritionist_e((string)($profile['barangay'] ?? 'All barangays')); ?></div>
		<div class="admin-stat-note">Scope for records and appointments</div>
	</article>
	<article class="nutritionist-stat-card">
		<div class="nutritionist-stat-label">Email</div>
		<div class="admin-stat-value"><?php echo nutritionist_e((string)($profile['email'] ?? '')); ?></div>
		<div class="admin-stat-note">Used for sign-in and alerts</div>
	</article>
</section>

<section class="nutritionist-panel-grid is-balanced">
	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Profile Information</div>
		<form method="post" class="nutritionist-form-grid is-single">
			<input type="hidden" name="action" value="update_profile">
			<label class="admin-field">
				<span>Full Name</span>
				<input name="name" required value="<?php echo nutritionist_e((string)($profile['name'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Email Address</span>
				<input type="email" name="email" required value="<?php echo nutritionist_e((string)($profile['email'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Phone Number</span>
				<input name="phone" value="<?php echo nutritionist_e((string)($profile['phone'] ?? '')); ?>">
			</label>
			<label class="admin-field">
				<span>Assigned Barangay</span>
				<select name="barangay_id">
					<option value="">-- All barangays --</option>
					<?php foreach ($barangays as $barangay): ?>
						<option value="<?php echo (int)$barangay['id']; ?>" <?php echo (int)($profile['barangay_id'] ?? 0) === (int)$barangay['id'] ? 'selected' : ''; ?>><?php echo nutritionist_e($barangay['name']); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="admin-field">
				<span>New Password</span>
				<input type="password" name="password" placeholder="Leave blank to keep current password">
			</label>
			<div class="admin-field" style="align-content:end;">
				<span>&nbsp;</span>
				<button class="admin-btn" type="submit">Save profile</button>
			</div>
		</form>
	</article>

	<article class="nutritionist-panel">
		<div class="admin-section-title" style="margin-bottom:12px;">Account Summary</div>
		<div style="display:grid;gap:10px;">
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Role</span>
				<strong><?php echo nutritionist_e(ucfirst((string)($profile['role_name'] ?? 'nutritionist'))); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Status</span>
				<strong><?php echo nutritionist_e(ucfirst((string)($profile['status'] ?? 'active'))); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Staff ID</span>
				<strong><?php echo (int)($profile['id'] ?? 0); ?></strong>
			</div>
			<div class="admin-list-item" style="padding:10px 0;">
				<span class="admin-mini">Security</span>
				<strong>Use the shared login flow</strong>
			</div>
		</div>
	</article>
</section>

<section class="nutritionist-panel" id="calendar-management" style="margin-top:16px;">
	<div class="nutritionist-toolbar" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Manage Calendar</h2>
			<p class="admin-section-subtitle">Add, edit, or delete meetings and Oplan Timbang entries. The dashboard calendar is read-only.</p>
		</div>
		<div style="display:flex;align-items:center;gap:6px;">
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($prevCalMonthLink); ?>" style="min-height:24px;padding:0 8px;line-height:24px;">&lt;</a>
			<span style="font-size:12px;font-weight:600;color:var(--admin-text);min-width:110px;text-align:center;"><?php echo nutritionist_e($calendarDate->format('F Y')); ?></span>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e($nextCalMonthLink); ?>" style="min-height:24px;padding:0 8px;line-height:24px;">&gt;</a>
		</div>
	</div>

	<?php
	$calFirstWeekday = (int)$calendarDate->format('w');
	$calDaysInMonth = (int)$calendarDate->format('t');
	$calCells = array_merge(array_fill(0, $calFirstWeekday, null), range(1, $calDaysInMonth));

	while (count($calCells) % 7 !== 0) {
		$calCells[] = null;
	}

	$calEntriesByDay = [];

	foreach ($calMonthEvents as $eventRow) {
		$eventDay = (int)(new DateTimeImmutable((string)$eventRow['event_date']))->format('j');
		$calEntriesByDay[$eventDay][] = $eventRow['event_type'];
	}
	?>
	<div class="nutritionist-calendar-grid" style="margin-bottom:4px;">
		<?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $dayLabel): ?>
			<div style="text-align:center;font-size:10px;color:var(--admin-muted);padding:3px 0;font-weight:500;"><?php echo nutritionist_e($dayLabel); ?></div>
		<?php endforeach; ?>
	</div>
	<div class="nutritionist-calendar-grid" style="margin-bottom:14px;">
		<?php foreach ($calCells as $day): ?>
			<?php if ($day === null): ?>
				<div></div>
			<?php else: ?>
				<?php $isToday = $day === (int)$today->format('j') && $calendarDate->format('Y-m') === $today->format('Y-m'); ?>
				<div class="nutritionist-calendar-day<?php echo $isToday ? ' is-today' : ''; ?>">
					<div style="line-height:1;font-size:11px;font-weight:<?php echo $isToday ? 600 : 400; ?>;width:<?php echo $isToday ? 22 : 0; ?>px;height:<?php echo $isToday ? 22 : 0; ?>px;border-radius:<?php echo $isToday ? '50%' : '0'; ?>;display:flex;align-items:center;justify-content:center;<?php echo $isToday ? 'background:var(--admin-primary);color:#fff;' : 'color:var(--admin-text);'; ?>">
						<?php echo (int)$day; ?>
					</div>
					<?php if (isset($calEntriesByDay[$day])): ?>
						<div class="nutritionist-calendar-dots">
							<?php foreach (array_slice($calEntriesByDay[$day], 0, 3) as $entryType): ?>
								<div class="nutritionist-dot" style="background:<?php echo $isToday ? 'rgba(255,255,255,.8)' : nutritionist_e(nutritionist_calendar_color((string)$entryType)); ?>;"></div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
	<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
		<div class="nutritionist-legend-item"><span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color('meeting')); ?>"></span>Meeting</div>
		<div class="nutritionist-legend-item"><span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color('oplan_timbang')); ?>"></span>Oplan Timbang</div>
	</div>

	<?php if ($calMonthEvents === []): ?>
		<div class="admin-stat-note" style="margin-bottom:14px;">No meetings or Oplan Timbang sessions scheduled for <?php echo nutritionist_e($calendarDate->format('F Y')); ?> yet.</div>
	<?php else: ?>
		<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px;">
			<?php foreach ($calMonthEvents as $eventRow): ?>
				<?php $eventDate = new DateTimeImmutable((string)$eventRow['event_date']); ?>
				<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:1px solid var(--admin-border);border-radius:8px;">
					<div style="display:flex;align-items:center;gap:8px;min-width:0;">
						<span class="nutritionist-dot" style="background:<?php echo nutritionist_e(nutritionist_calendar_color((string)$eventRow['event_type'])); ?>;flex-shrink:0;"></span>
						<div style="min-width:0;">
							<div style="font-weight:600;font-size:12px;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo nutritionist_e((string)$eventRow['title']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e(nutritionist_calendar_label((string)$eventRow['event_type'])); ?> · <?php echo nutritionist_e($eventDate->format('d M Y')); ?><?php echo $eventRow['event_time'] !== null ? ' · ' . nutritionist_e((new DateTimeImmutable('1970-01-01 ' . $eventRow['event_time']))->format('g:i A')) : ''; ?><?php echo $eventRow['location'] !== null && $eventRow['location'] !== '' ? ' · ' . nutritionist_e((string)$eventRow['location']) : ''; ?></div>
						</div>
					</div>
					<div class="admin-actions">
						<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/settings.php?' . http_build_query(['cal_month' => $calMonthParam, 'edit_event' => (int)$eventRow['id']]) . '#calendar-event-form')); ?>">Edit</a>
						<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/settings.php')); ?>" onsubmit="return confirm('Remove this event?');">
							<input type="hidden" name="action" value="delete_event">
							<input type="hidden" name="id" value="<?php echo (int)$eventRow['id']; ?>">
							<input type="hidden" name="cal_month" value="<?php echo nutritionist_e($calMonthParam); ?>">
							<button class="admin-btn-danger" type="submit">Delete</button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ($editingEvent !== null): ?>
		<div class="admin-flash" style="margin-bottom:14px;background:#fff4df;color:#9a6510;border:1px solid #f0c675;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
			<span>✏️ Editing <strong><?php echo nutritionist_e((string)$editingEvent['title']); ?></strong> — update the fields below, then click <strong>Update event</strong>.</span>
			<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/settings.php?' . http_build_query(['cal_month' => $calMonthParam]) . '#calendar-management')); ?>">Cancel edit</a>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/settings.php')); ?>" id="calendar-event-form" class="nutritionist-form-grid" style="<?php echo $editingEvent !== null ? 'border:2px solid var(--admin-primary);border-radius:12px;padding:14px;' : ''; ?>">
		<input type="hidden" name="action" value="<?php echo $editingEvent !== null ? 'update_event' : 'create_event'; ?>">
		<input type="hidden" name="id" value="<?php echo $editingEvent !== null ? (int)$editingEvent['id'] : ''; ?>">
		<input type="hidden" name="cal_month" value="<?php echo nutritionist_e($calMonthParam); ?>">
		<label class="admin-field">
			<span>Event type</span>
			<select name="event_type" required>
				<option value="meeting" <?php echo ($editingEvent['event_type'] ?? '') === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
				<option value="oplan_timbang" <?php echo ($editingEvent['event_type'] ?? '') === 'oplan_timbang' ? 'selected' : ''; ?>>Oplan Timbang</option>
			</select>
		</label>
		<label class="admin-field">
			<span>Title</span>
			<input name="title" required value="<?php echo nutritionist_e((string)($editingEvent['title'] ?? '')); ?>" placeholder="e.g. Barangay nutrition meeting">
		</label>
		<label class="admin-field">
			<span>Date</span>
			<input type="date" name="event_date" required value="<?php echo nutritionist_e((string)($editingEvent['event_date'] ?? '')); ?>">
		</label>
		<label class="admin-field">
			<span>Time (optional)</span>
			<input type="time" name="event_time" value="<?php echo nutritionist_e((string)substr((string)($editingEvent['event_time'] ?? ''), 0, 5)); ?>">
		</label>
		<label class="admin-field">
			<span>Location (optional)</span>
			<input name="location" value="<?php echo nutritionist_e((string)($editingEvent['location'] ?? '')); ?>" placeholder="e.g. Barangay hall">
		</label>
		<label class="admin-field">
			<span>Notes (optional)</span>
			<input name="notes" value="<?php echo nutritionist_e((string)($editingEvent['notes'] ?? '')); ?>">
		</label>
		<div class="admin-field" style="align-content:end;grid-column:1 / -1;display:flex;gap:8px;">
			<button class="admin-btn" type="submit"><?php echo $editingEvent !== null ? 'Update event' : 'Add to calendar'; ?></button>
			<?php if ($editingEvent !== null): ?>
				<a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/settings.php?' . http_build_query(['cal_month' => $calMonthParam]) . '#calendar-management')); ?>">Cancel edit</a>
			<?php endif; ?>
		</div>
	</form>
</section>
<?php
nutritionist_layout_end();