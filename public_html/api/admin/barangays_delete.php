<?php

require_once __DIR__ . '/../../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.manage');

// Hard delete retired: barangays are Active/Inactive only now, because a
// hard DELETE nulls children/parents/kiosk assignments (ON DELETE SET NULL)
// and wipes local areas (ON DELETE CASCADE), orphaning dashboard data.
// Use barangays_update.php with status=inactive to deactivate instead.
admin_redirect('/admin/barangays.php', ['notice' => 'Barangay delete was retired. Use Deactivate instead — history stays intact.', 'type' => 'error']);
