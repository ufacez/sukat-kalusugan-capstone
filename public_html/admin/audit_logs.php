<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('audit_logs.view');

$levelCounts = [
    'info' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'info'"),
    'warning' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'warning'"),
    'danger' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'danger'"),
];

$todayCount = admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()");
$weekCount = admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$uniqueUsers = admin_scalar("SELECT COUNT(DISTINCT user_id) FROM audit_logs WHERE user_id IS NOT NULL");

$actionFilter = $_GET['action'] ?? '';
$filterWhere = '';
$filterTypes = '';
$filterParams = [];
if ($actionFilter === 'login') {
    $filterWhere = "AND a.action = 'LOGIN'";
} elseif ($actionFilter === 'logout') {
    $filterWhere = "AND a.action = 'LOGOUT'";
} elseif ($actionFilter === 'create') {
    $filterWhere = "AND (a.action LIKE 'CREATE_%' OR a.action = 'measurement.create')";
} elseif ($actionFilter === 'read') {
    $filterWhere = "AND a.action IN ('EOPT_EXPORT','EOPT_LIST_EXPORT','FOLLOWUP_SYNC','PASSWORD_RESET_REQUEST','PASSWORD_RESET_COMPLETE')";
} elseif ($actionFilter === 'update') {
    $filterWhere = "AND a.action LIKE 'UPDATE_%'";
} elseif ($actionFilter === 'delete') {
    $filterWhere = "AND a.action LIKE 'DELETE_%'";
}

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$filteredCount = (int)admin_scalar(
    "SELECT COUNT(*) FROM audit_logs a WHERE 1=1 " . $filterWhere
);
$totalPages = max(1, (int)ceil($filteredCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logs = admin_fetch_all(
    'SELECT a.id, a.action, a.level, a.description, a.ip_address, a.created_at, a.user_type,
            COALESCE(u.email, "System") AS actor,
            COALESCE(u.name, "System") AS actor_name,
            COALESCE(a.user_type, r.name, "system") AS resolved_type
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE 1=1 ' . $filterWhere . '
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT ? OFFSET ?',
    'ii',
    [$perPage, $offset]
);

$searchHtml = '';

admin_layout_start('Audit Logs', 'Track user activity, security events, and system changes across all modules.', 'audit_logs', $searchHtml);
?>

<style>
.audit-hero{display:grid;grid-template-columns:1fr 360px;gap:14px;margin-bottom:20px}
@media(max-width:1100px){.audit-hero{grid-template-columns:1fr}}

.audit-chart-wrap{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;padding:18px;position:relative;overflow:hidden}
.audit-chart-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.audit-chart-title{font-size:13px;font-weight:700;color:var(--admin-text)}
.audit-chart-badge{font-size:9px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--admin-primary-soft);color:var(--admin-primary);display:flex;align-items:center;gap:4px}
.audit-chart-badge::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--admin-primary);animation:pulse-dot 2s infinite}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}
.audit-chart-legend{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.audit-legend-item{display:flex;align-items:center;gap:4px;font-size:9px;font-weight:500;color:var(--admin-muted)}
.audit-legend-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.audit-chart-body{position:relative}
.audit-chart-canvas{width:100%;height:260px;cursor:crosshair}
.audit-chart-y-axis{position:absolute;left:2px;top:0;bottom:0;width:28px;display:flex;flex-direction:column;justify-content:space-between;pointer-events:none}
.audit-chart-y-label{font-size:8px;color:var(--admin-muted);font-family:Inter,monospace;text-align:right}
.audit-chart-x-axis{display:flex;justify-content:space-between;padding:2px 30px 0 30px}
.audit-chart-x-label{font-size:8px;color:var(--admin-muted);font-family:Inter,monospace}
.audit-chart-tooltip{position:absolute;pointer-events:none;background:var(--admin-text);color:var(--admin-surface);padding:6px 10px;border-radius:6px;font-size:10px;font-weight:500;white-space:nowrap;transform:translate(-50%,0);opacity:0;transition:opacity .12s;z-index:10;line-height:1.5;max-width:300px}
.audit-chart-tooltip::after{content:'';position:absolute;left:50%;top:100%;transform:translateX(-50%);border:3px solid transparent;border-top-color:var(--admin-text)}

.audit-ai-wrap{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;padding:18px;display:flex;flex-direction:column}
.audit-ai-header{display:flex;align-items:center;gap:8px;margin-bottom:12px}
.audit-ai-icon{width:28px;height:28px;border-radius:7px;background:var(--admin-primary-soft);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.audit-ai-icon svg{width:14px;height:14px;color:var(--admin-primary)}
.audit-ai-title{font-size:13px;font-weight:700;color:var(--admin-text)}
.audit-ai-subtitle{font-size:9px;color:var(--admin-muted)}
.audit-ai-insights{flex:1;display:flex;flex-direction:column;gap:0;overflow-y:auto;max-height:220px;scrollbar-width:thin;scrollbar-color:var(--admin-border) transparent}
.audit-ai-insights::-webkit-scrollbar{width:4px}
.audit-ai-insights::-webkit-scrollbar-track{background:transparent}
.audit-ai-insights::-webkit-scrollbar-thumb{background:var(--admin-border);border-radius:4px}
.audit-ai-item{padding:7px 10px 7px 22px;font-size:11px;line-height:1.45;color:var(--admin-text);position:relative;border-bottom:1px solid var(--admin-border)}
.audit-ai-item:last-of-type{border-bottom:none}
.audit-ai-item::before{content:'';position:absolute;left:8px;top:11px;width:4px;height:4px;border-radius:50%;background:var(--admin-primary)}
.audit-ai-source{margin-top:8px;padding-top:6px;border-top:1px solid var(--admin-border);font-size:8px;color:var(--admin-muted);display:flex;justify-content:space-between;align-items:center}
.audit-ai-loading{display:flex;align-items:center;justify-content:center;flex:1;color:var(--admin-muted);font-size:11px;gap:5px}
.audit-ai-loading .dot{width:4px;height:4px;border-radius:50%;background:var(--admin-primary);animation:ai-bounce .6s infinite alternate}
.audit-ai-loading .dot:nth-child(2){animation-delay:.2s}
.audit-ai-loading .dot:nth-child(3){animation-delay:.4s}
@keyframes ai-bounce{to{opacity:.3;transform:translateY(-3px)}}

.audit-stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
@media(max-width:800px){.audit-stats-row{grid-template-columns:repeat(2,1fr)}}
.audit-stat{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px}
.audit-stat-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.audit-stat-icon svg{width:16px;height:16px}
.audit-stat-icon.is-green{background:rgba(34,197,94,0.12);color:#16a34a}
.audit-stat-icon.is-yellow{background:rgba(234,179,8,0.12);color:#ca8a04}
.audit-stat-icon.is-orange{background:rgba(249,115,22,0.12);color:#ea580c}
.audit-stat-icon.is-red{background:rgba(239,68,68,0.12);color:#dc2626}
.audit-stat-value{font-size:18px;font-weight:800;color:var(--admin-text);line-height:1}
.audit-stat-label{font-size:9px;color:var(--admin-muted);margin-top:1px}

.audit-user-cell{display:flex;align-items:center;gap:7px}
.audit-user-avatar{width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;color:#fff;flex-shrink:0}
.audit-user-avatar.is-admin{background:#16a34a}
.audit-user-avatar.is-nutritionist{background:#6366f1}
.audit-user-avatar.is-parent{background:#f59e0b}
.audit-user-avatar.is-system{background:#94a3b8}
.audit-user-info{min-width:0;flex:1}
.audit-user-name{font-size:12px;font-weight:600;color:var(--admin-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.audit-user-role{font-size:9px;color:var(--admin-muted);letter-spacing:.2px}

.audit-action-pill{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:600;white-space:nowrap}
.audit-action-pill.is-create{background:rgba(34,197,94,0.10);color:#16a34a}
.audit-action-pill.is-read{background:rgba(99,102,241,0.10);color:#6366f1}
.audit-action-pill.is-update{background:rgba(59,130,246,0.10);color:#2563eb}
.audit-action-pill.is-delete{background:rgba(239,68,68,0.10);color:#dc2626}
.audit-action-pill.is-login{background:rgba(34,197,94,0.10);color:#16a34a}
.audit-action-pill.is-logout{background:rgba(148,163,184,0.12);color:#64748b}

.audit-time-cell{line-height:1.2}
.audit-time-date{font-size:11px;font-weight:600;color:var(--admin-text)}
.audit-time-clock{font-size:10px;color:var(--admin-muted);font-family:Inter,monospace}

.audit-desc-cell{font-size:11px;color:var(--admin-muted);line-height:1.35;max-width:260px}
.audit-level-dot{width:6px;height:6px;border-radius:50%;display:inline-block;margin-right:4px;vertical-align:middle}
.audit-level-dot.is-info{background:#16a34a}
.audit-level-dot.is-warning{background:#f59e0b}
.audit-level-dot.is-danger{background:#dc2626}

.admin-pill.is-indigo{background:rgba(99,102,241,0.10);color:#6366f1}

.admin-table th,.admin-table td{padding:8px 12px;vertical-align:middle}

.audit-filter-wrap{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.audit-search-wrap{display:flex;align-items:center;gap:6px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:8px;padding:0 10px;flex:1;min-width:200px;max-width:320px}
.audit-search-wrap svg{width:14px;height:14px;color:var(--admin-muted);flex-shrink:0}
.audit-search-input{border:none;background:transparent;outline:none;font-size:12px;color:var(--admin-text);padding:7px 0;width:100%;font-family:Inter,sans-serif}
.audit-search-input::placeholder{color:var(--admin-muted)}

.audit-dropdown{position:relative}
.audit-dropdown-trigger{display:flex;align-items:center;gap:6px;padding:7px 12px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:8px;cursor:pointer;font-size:12px;font-weight:500;color:var(--admin-text);font-family:Inter,sans-serif;transition:border-color .15s}
.audit-dropdown-trigger:hover{border-color:var(--admin-primary)}
.audit-dropdown-trigger svg{width:14px;height:14px;color:var(--admin-muted)}
.audit-dropdown-trigger svg:first-child{display:none}
.audit-dropdown-chevron{transition:transform .2s}
.audit-dropdown.is-open .audit-dropdown-chevron{transform:rotate(180deg)}

.audit-dropdown-menu{position:absolute;top:calc(100% + 4px);right:0;min-width:200px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:50;padding:4px;opacity:0;visibility:hidden;transform:translateY(-4px);transition:all .15s}
.audit-dropdown.is-open .audit-dropdown-menu{opacity:1;visibility:visible;transform:translateY(0)}
.audit-dropdown-group-label{font-size:9px;font-weight:600;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;padding:6px 10px 2px;user-select:none}
.audit-dropdown-divider{height:1px;background:var(--admin-border);margin:3px 8px}
.audit-dropdown-item{display:flex;align-items:center;gap:8px;width:100%;padding:6px 10px;border:none;background:transparent;border-radius:6px;cursor:pointer;font-size:12px;font-weight:500;color:var(--admin-text);font-family:Inter,sans-serif;text-align:left;transition:background .1s;text-decoration:none}
.audit-dropdown-item:hover{background:var(--admin-surface-alt)}
.audit-dropdown-item.is-active{background:var(--admin-primary-soft);color:var(--admin-primary);font-weight:600}
.audit-dropdown-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
</style>

<section class="audit-stats-row">
    <article class="audit-stat">
        <div class="audit-stat-icon is-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
        </div>
        <div>
            <div class="audit-stat-value"><?php echo number_format($todayCount); ?></div>
            <div class="audit-stat-label">Today</div>
        </div>
    </article>
    <article class="audit-stat">
        <div class="audit-stat-icon is-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
        </div>
        <div>
            <div class="audit-stat-value"><?php echo number_format($weekCount); ?></div>
            <div class="audit-stat-label">This Week</div>
        </div>
    </article>
    <article class="audit-stat">
        <div class="audit-stat-icon is-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
        </div>
        <div>
            <div class="audit-stat-value"><?php echo number_format($uniqueUsers); ?></div>
            <div class="audit-stat-label">Active Users</div>
        </div>
    </article>
    <article class="audit-stat">
        <div class="audit-stat-icon is-green">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
        </div>
        <div>
            <div class="audit-stat-value"><?php echo number_format($filteredCount); ?></div>
            <div class="audit-stat-label">Total Events</div>
        </div>
    </article>
</section>

<section class="audit-hero">
    <div class="audit-chart-wrap">
        <div class="audit-chart-header">
            <div class="audit-chart-title">Activity Over Time</div>
            <div class="audit-chart-legend" id="audit-legend">
                <span class="audit-legend-item"><span class="audit-legend-dot" style="background:#64748b"></span>Auth</span>
                <span class="audit-legend-item"><span class="audit-legend-dot" style="background:#16a34a"></span>Create</span>
                <span class="audit-legend-item"><span class="audit-legend-dot" style="background:#6366f1"></span>Read</span>
                <span class="audit-legend-item"><span class="audit-legend-dot" style="background:#2563eb"></span>Update</span>
                <span class="audit-legend-item"><span class="audit-legend-dot" style="background:#dc2626"></span>Delete</span>
            </div>
        </div>
        <div class="audit-chart-body">
            <canvas id="auditWaveChart" class="audit-chart-canvas"></canvas>
            <div class="audit-chart-y-axis" id="audit-y-axis"></div>
            <div class="audit-chart-tooltip" id="audit-tooltip"></div>
        </div>
        <div class="audit-chart-x-axis" id="audit-x-axis"></div>
    </div>
    <div class="audit-ai-wrap">
        <div class="audit-ai-header">
            <div class="audit-ai-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/></svg>
            </div>
            <div>
                <div class="audit-ai-title">AI Insights</div>
                <div class="audit-ai-subtitle">Automated analysis</div>
            </div>
        </div>
        <div class="audit-ai-insights" id="audit-ai-panel">
            <div class="audit-ai-loading">
                <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                <span>Analyzing...</span>
            </div>
        </div>
    </div>
</section>

<section class="admin-section">
    <div class="audit-filter-wrap">
        <div class="audit-search-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            <input class="audit-search-input" type="search" placeholder="Search logs..." id="audit-search">
        </div>
        <?php
        $filterLabels = ['login'=>'Login','logout'=>'Logout','create'=>'Create','read'=>'Read','update'=>'Update','delete'=>'Delete'];
        $currentLabel = $actionFilter !== '' && isset($filterLabels[$actionFilter]) ? $filterLabels[$actionFilter] : 'All Actions';
        $filterUrl = function($action) {
            $params = $_GET;
            unset($params['page']);
            if ($action !== '') $params['action'] = $action; else unset($params['action']);
            return admin_e(app_url('/admin/audit_logs.php') . '?' . http_build_query($params));
        };
        ?>
        <div class="audit-dropdown" id="audit-dropdown">
            <button class="audit-dropdown-trigger" id="audit-dropdown-trigger" type="button">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z"/></svg>
                <span id="audit-dropdown-label"><?php echo admin_e($currentLabel); ?></span>
                <svg class="audit-dropdown-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
            </button>
            <div class="audit-dropdown-menu" id="audit-dropdown-menu">
                <a class="audit-dropdown-item<?php echo $actionFilter === '' ? ' is-active' : ''; ?>" href="<?php echo $filterUrl(''); ?>">
                    <span class="audit-dropdown-dot" style="background:var(--admin-muted)"></span>
                    All Actions
                </a>
                <div class="audit-dropdown-divider"></div>
                <div class="audit-dropdown-group">
                    <div class="audit-dropdown-group-label">Authentication</div>
                    <a class="audit-dropdown-item<?php echo $actionFilter === 'login' ? ' is-active' : ''; ?>" href="<?php echo $filterUrl('login'); ?>">
                        <span class="audit-dropdown-dot" style="background:#16a34a"></span>
                        Login
                    </a>
                    <a class="audit-dropdown-item<?php echo $actionFilter === 'logout' ? ' is-active' : ''; ?>" href="<?php echo $filterUrl('logout'); ?>">
                        <span class="audit-dropdown-dot" style="background:#64748b"></span>
                        Logout
                    </a>
                </div>
                <div class="audit-dropdown-divider"></div>
                <div class="audit-dropdown-group">
                    <div class="audit-dropdown-group-label">Data Operations</div>
                    <a class="audit-dropdown-item<?php echo $actionFilter === 'create' ? ' is-active' : ''; ?>" href="<?php echo $filterUrl('create'); ?>">
                        <span class="audit-dropdown-dot" style="background:#16a34a"></span>
                        Create
                    </a>
                    <a class="audit-dropdown-item<?php echo $actionFilter === 'read' ? ' is-active' : ''; ?>" href="<?php echo $filterUrl('read'); ?>">
                        <span class="audit-dropdown-dot" style="background:#6366f1"></span>
                        Read
                    </a>
                    <a class="audit-dropdown-item<?php echo $actionFilter === 'update' ? ' is-active' : ''; ?>" href="<?php echo $filterUrl('update'); ?>">
                        <span class="audit-dropdown-dot" style="background:#2563eb"></span>
                        Update
                    </a>
                    <a class="audit-dropdown-item<?php echo $actionFilter === 'delete' ? ' is-active' : ''; ?>" href="<?php echo $filterUrl('delete'); ?>">
                        <span class="audit-dropdown-dot" style="background:#dc2626"></span>
                        Delete
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="audit-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Date &amp; Time</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $resolvedType = strtolower($log['resolved_type'] ?? 'admin');
                    $avatarColor = admin_avatar_color($log['actor_name'] ?? 'System');
                    $roleLabel = match($resolvedType) {
                        'admin' => 'Admin',
                        'nutritionist' => 'Nutritionist',
                        'parent' => 'Parent',
                        default => ucfirst($resolvedType),
                    };
                    $initials = $log['actor_name'] !== 'System'
                        ? admin_initials($log['actor_name'])
                        : 'SY';
                    $rawAction = strtoupper($log['action']);
                    $pillClass = match(true) {
                        $rawAction === 'LOGIN' || $rawAction === 'LOGOUT' => 'is-muted',
                        str_starts_with($rawAction, 'CREATE') || str_starts_with($rawAction, 'MEASUREMENT') => 'is-success',
                        str_starts_with($rawAction, 'EOPT') || str_starts_with($rawAction, 'FOLLOWUP') || str_starts_with($rawAction, 'PASSWORD_RESET') => 'is-indigo',
                        str_starts_with($rawAction, 'UPDATE') => 'is-info',
                        str_starts_with($rawAction, 'DELETE') => 'is-danger',
                        default => 'is-muted',
                    };
                    $ts = $log['created_at'];
                    $dateObj = new DateTime($ts);
                    $dateLine = $dateObj->format('M j Y');
                    $timeLine = $dateObj->format('H:i');

                    $actionLabel = $log['action'];
                    if (str_starts_with($actionLabel, 'CREATE_')) $actionLabel = 'Create ' . strtolower(substr($actionLabel, 7));
                    elseif (str_starts_with($actionLabel, 'UPDATE_')) $actionLabel = 'Update ' . strtolower(substr($actionLabel, 7));
                    elseif (str_starts_with($actionLabel, 'DELETE_')) $actionLabel = 'Delete ' . strtolower(substr($actionLabel, 7));
                    elseif ($actionLabel === 'LOGIN') $actionLabel = 'Login';
                    elseif ($actionLabel === 'LOGOUT') $actionLabel = 'Logout';
                    elseif ($actionLabel === 'measurement.create') $actionLabel = 'Create measurement';
                    elseif ($actionLabel === 'EOPT_EXPORT' || $actionLabel === 'EOPT_LIST_EXPORT') $actionLabel = 'Export EOPT report';
                    elseif ($actionLabel === 'FOLLOWUP_SYNC') $actionLabel = 'Sync follow-ups';
                    elseif ($actionLabel === 'PASSWORD_RESET_REQUEST') $actionLabel = 'Request password reset';
                    elseif ($actionLabel === 'PASSWORD_RESET_COMPLETE') $actionLabel = 'Complete password reset';
                    ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($log['actor'] . ' ' . $log['actor_name'] . ' ' . $actionLabel . ' ' . ($log['description'] ?? '') . ' ' . $roleLabel)); ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:<?php echo admin_e($avatarColor); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_e($initials); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e($log['actor_name']); ?></div>
                                    <div class="admin-mini"><?php echo admin_e($log['actor'] ?? ''); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="admin-pill <?php echo admin_e(match($resolvedType) {
                            'admin' => 'is-warn',
                            'nutritionist' => 'is-success',
                            'parent' => 'is-info',
                            default => 'is-muted',
                        }); ?>"><?php echo admin_e($roleLabel); ?></span></td>
                        <td><span class="admin-pill <?php echo admin_e($pillClass); ?>"><?php echo admin_e($actionLabel); ?></span></td>
                        <td>
                            <div><?php echo admin_e($dateLine); ?></div>
                            <div class="admin-mini"><?php echo admin_e($timeLine); ?></div>
                        </td>
                        <td style="color:var(--admin-muted);font-size:0.82rem;"><?php echo admin_e($log['description'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-top:1px solid var(--admin-border);">
            <span class="admin-pagination-status">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            <div class="admin-pagination-numbers">
                <?php
                $pParams = $_GET;
                unset($pParams['page']);
                $qs = http_build_query($pParams);
                $prefix = $qs ? $qs . '&' : '';
                $range = 1;
                $start = max(1, $page - $range);
                $end = min($totalPages, $page + $range);
                ?>
                <a class="admin-icon-btn" href="?<?php echo admin_e($prefix . 'page=' . ($page - 1)); ?>" <?php echo $page <= 1 ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </a>
                <?php if ($start > 1): ?>
                    <a class="admin-page-num" href="?<?php echo admin_e($prefix . 'page=1'); ?>">1</a>
                    <?php if ($start > 2): ?><span style="color:var(--admin-muted);padding:0 4px;">...</span><?php endif; ?>
                <?php endif;
                for ($i = $start; $i <= $end; $i++): ?>
                    <a class="admin-page-num<?php echo $i === $page ? ' is-active' : ''; ?>" href="?<?php echo admin_e($prefix . 'page=' . $i); ?>"><?php echo $i; ?></a>
                <?php endfor;
                if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span style="color:var(--admin-muted);padding:0 4px;">...</span><?php endif; ?>
                    <a class="admin-page-num" href="?<?php echo admin_e($prefix . 'page=' . $totalPages); ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                <a class="admin-icon-btn" href="?<?php echo admin_e($prefix . 'page=' . ($page + 1)); ?>" <?php echo $page >= $totalPages ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function(){
    var api = '<?php echo admin_e(app_url("/api/admin/audit_insights.php")); ?>';
    var catData = null;
    var padL = 30, padR = 10, padT = 10, padB = 22;
    var cW, cH, W, H, maxVal;
    var canvas, ctx, dpr;
    var hoverIndex = -1;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    var categories = [
        { key: 'auth',   color: '#64748b', fill: 'rgba(100,116,139,0.12)', label: 'Auth' },
        { key: 'create', color: '#16a34a', fill: 'rgba(22,163,74,0.10)',   label: 'Create' },
        { key: 'read',   color: '#6366f1', fill: 'rgba(99,102,241,0.10)',  label: 'Read' },
        { key: 'update', color: '#2563eb', fill: 'rgba(37,99,235,0.10)',   label: 'Update' },
        { key: 'delete', color: '#dc2626', fill: 'rgba(220,38,38,0.10)',   label: 'Delete' }
    ];

    function formatDate(s){
        var d = new Date(s + 'T00:00:00');
        return d.getDate() + ' ' + months[d.getMonth()];
    }
    function formatFull(s){
        var d = new Date(s + 'T00:00:00');
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }
    function catmullRom(p0,p1,p2,p3,t){
        var t2=t*t, t3=t2*t;
        return 0.5*((2*p1)+(-p0+p2)*t+(2*p0-5*p1+4*p2-p3)*t2+(-p0+3*p1-3*p2+p3)*t3);
    }
    function drawSmoothLine(points, strokeColor, fillColor, lineWidth, isHoverLine){
        if(points.length < 2) return;
        ctx.beginPath();
        ctx.moveTo(points[0].x, H);
        ctx.lineTo(points[0].x, points[0].y);
        for(var i=0;i<points.length-1;i++){
            var p0=points[Math.max(i-1,0)], p1=points[i], p2=points[i+1], p3=points[Math.min(i+2,points.length-1)];
            for(var t=0;t<=1;t+=0.05) ctx.lineTo(catmullRom(p0.x,p1.x,p2.x,p3.x,t), catmullRom(p0.y,p1.y,p2.y,p3.y,t));
        }
        ctx.lineTo(points[points.length-1].x, H);
        ctx.closePath();
        ctx.fillStyle = fillColor;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(points[0].x, points[0].y);
        for(var i=0;i<points.length-1;i++){
            var p0=points[Math.max(i-1,0)], p1=points[i], p2=points[i+1], p3=points[Math.min(i+2,points.length-1)];
            for(var t=0;t<=1;t+=0.05) ctx.lineTo(catmullRom(p0.x,p1.x,p2.x,p3.x,t), catmullRom(p0.y,p1.y,p2.y,p3.y,t));
        }
        ctx.strokeStyle = strokeColor;
        ctx.lineWidth = isHoverLine ? 3 : 1.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.stroke();
    }

    function initChart(cat){
        catData = cat;
        canvas = document.getElementById('auditWaveChart');
        if(!canvas) return;
        ctx = canvas.getContext('2d');
        dpr = window.devicePixelRatio || 1;
        var rect = canvas.parentElement.getBoundingClientRect();
        W = rect.width; H = 260;
        canvas.width = W*dpr; canvas.height = H*dpr;
        canvas.style.width = W+'px'; canvas.style.height = H+'px';
        ctx.scale(dpr,dpr);
        cW = W - padL - padR;
        cH = H - padT - padB;

        maxVal = 1;
        cat.forEach(function(d){
            categories.forEach(function(c){ if(d[c.key]>maxVal) maxVal=d[c.key]; });
        });
        maxVal = Math.ceil(maxVal * 1.25) || 1;

        var yEl = document.getElementById('audit-y-axis');
        yEl.innerHTML = '';
        for(var i=4;i>=0;i--){
            var lbl = document.createElement('div');
            lbl.className = 'audit-chart-y-label';
            lbl.textContent = Math.round(maxVal*i/4);
            yEl.appendChild(lbl);
        }
        var xEl = document.getElementById('audit-x-axis');
        xEl.innerHTML = '';
        cat.forEach(function(d,i){
            var lbl = document.createElement('div');
            lbl.className = 'audit-chart-x-label';
            lbl.textContent = (i%5===0||i===cat.length-1) ? formatDate(d.date) : '';
            xEl.appendChild(lbl);
        });

        drawAll();
        setupHover();
    }

    function buildPoints(key){
        return catData.map(function(d,i){
            return {x: padL+(i/Math.max(catData.length-1,1))*cW, y: padT+cH-(d[key]/maxVal)*cH};
        });
    }

    function drawAll(){
        ctx.clearRect(0,0,W,H);
        var border = getComputedStyle(document.documentElement).getPropertyValue('--admin-border').trim()||'#d7e4dc';
        ctx.strokeStyle = border; ctx.lineWidth = 0.5;
        for(var g=0;g<=4;g++){
            var gy = padT+cH*(g/4);
            ctx.beginPath(); ctx.moveTo(padL,gy); ctx.lineTo(W-padR,gy); ctx.stroke();
        }

        categories.forEach(function(c,ci){
            var pts = buildPoints(c.key);
            var isHl = (hoverIndex === ci);
            drawSmoothLine(pts, c.color, c.fill, isHl ? 3 : 1.5, isHl);
        });

        categories.forEach(function(c,ci){
            var pts = buildPoints(c.key);
            if(hoverIndex === ci){
                pts.forEach(function(p){
                    ctx.beginPath(); ctx.arc(p.x,p.y,3,0,Math.PI*2);
                    ctx.fillStyle=c.color; ctx.fill();
                    ctx.beginPath(); ctx.arc(p.x,p.y,1.5,0,Math.PI*2);
                    ctx.fillStyle='#fff'; ctx.fill();
                });
            }
        });

        if(hoverIndex >= 0 && hoverIndex < catData.length){
            var xPos = padL+(hoverIndex/Math.max(catData.length-1,1))*cW;
            ctx.beginPath(); ctx.setLineDash([3,3]);
            ctx.moveTo(xPos,padT); ctx.lineTo(xPos,padT+cH);
            ctx.strokeStyle='rgba(0,0,0,0.15)'; ctx.lineWidth=1; ctx.stroke();
            ctx.setLineDash([]);
        }
    }

    function setupHover(){
        var tooltip = document.getElementById('audit-tooltip');
        canvas.addEventListener('mousemove', function(e){
            var rect = canvas.getBoundingClientRect();
            var mx = e.clientX - rect.left;
            var my = e.clientY - rect.top;
            var closest=-1, closestDist=Infinity;

            for(var ci=0;ci<categories.length;ci++){
                var pts = buildPoints(categories[ci].key);
                for(var pi=0;pi<pts.length;pi++){
                    var dist = Math.abs(pts[pi].x-mx);
                    if(dist<closestDist){ closestDist=dist; closest=pi; }
                }
            }

            if(closest>=0 && closestDist<45){
                hoverIndex = closest;
                var d = catData[closest];
                var lines = [formatFull(d.date)+':' ];
                categories.forEach(function(c){
                    if(d[c.key]>0) lines.push('<span style="color:'+c.color+'">'+c.label+'</span>: '+d[c.key]);
                });
                tooltip.innerHTML = lines.join(' &middot; ');
                var xPos = padL+(closest/Math.max(catData.length-1,1))*cW;
                tooltip.style.left = xPos+'px';
                tooltip.style.top = (padT)+'px';
                tooltip.style.opacity='1';

                var legendItems = document.querySelectorAll('.audit-legend-item');
                legendItems.forEach(function(li,ci2){
                    li.style.opacity = (d[categories[ci2].key]>0||ci2===categories.findIndex(function(c){return true;})) ? '1' : '0.35';
                });
            } else {
                hoverIndex=-1;
                tooltip.style.opacity='0';
                document.querySelectorAll('.audit-legend-item').forEach(function(li){li.style.opacity='1';});
            }
            drawAll();
        });

        canvas.addEventListener('mouseleave', function(){
            hoverIndex=-1;
            tooltip.style.opacity='0';
            document.querySelectorAll('.audit-legend-item').forEach(function(li){li.style.opacity='1';});
            drawAll();
        });

        window.addEventListener('resize', function(){
            var rect = canvas.parentElement.getBoundingClientRect();
            W = rect.width;
            canvas.width=W*dpr; canvas.style.width=W+'px';
            cW = W-padL-padR;
            drawAll();
        });
    }

    function renderInsights(result){
        var panel = document.getElementById('audit-ai-panel');
        if(!panel) return;
        var insights = (result.ai_insights && result.ai_insights.insights) ? result.ai_insights.insights : [];
        var html = '';
        insights.forEach(function(text){ html += '<div class="audit-ai-item">'+text+'</div>'; });
        if(insights.length===0) html = '<div class="audit-ai-item">No insights available yet.</div>';
        var src = (result.ai_insights && result.ai_insights.source==='ai') ? 'AI-powered' : 'Rule-based';
        html += '<div class="audit-ai-source"><span>'+src+'</span><span>Updated just now</span></div>';
        panel.innerHTML = html;
    }

    function loadInsights(){
        fetch(api,{credentials:'same-origin'})
            .then(function(r){return r.json()})
            .then(function(result){
                if(result.success && result.category_chart) initChart(result.category_chart);
                renderInsights(result);
            })
            .catch(function(){
                var panel=document.getElementById('audit-ai-panel');
                if(panel&&!panel.querySelector('.audit-ai-item'))
                    panel.innerHTML='<div class="audit-ai-item">Unable to load insights. Retrying...</div>';
                setTimeout(loadInsights,10000);
            });
    }

    loadInsights();
    setInterval(loadInsights,60000);

    var dropdown=document.getElementById('audit-dropdown');
    var trigger=document.getElementById('audit-dropdown-trigger');
    if(trigger&&dropdown){
        trigger.addEventListener('click',function(e){e.stopPropagation();dropdown.classList.toggle('is-open');});
        document.addEventListener('click',function(e){if(!dropdown.contains(e.target))dropdown.classList.remove('is-open');});
    }
})();
</script>

<?php
admin_layout_end();
