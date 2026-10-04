<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

// Backward-compat shim: Auto-Archive was renamed to Archive with tabs.
// Old bookmarks and sidebar caches land on the auto tab.
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = rtrim(APP_URL, '/') . '/admin/archive.php?tab=auto';
if (is_string($query) && $query !== '') {
	parse_str($query, $params);
	unset($params['tab']);
	$extra = http_build_query($params);
	if ($extra !== '') {
		$target .= '&' . $extra;
	}
}
header('Location: ' . $target, true, 302);
exit;
