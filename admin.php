<?php
/**
 * Admin page (Vue-powered frontend)
 */

require_once 'includes/Database.php';
require_once 'includes/Logger.php';

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $db = new Database();
    if (!$db->isInstalled()) {
        header('Location: install.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: install.php');
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$adminConfig = $db->getConfig('admin_config', []);
$adminPassword = $adminConfig['password'] ?? 'admin123';

$loginError = '';
if (isset($_POST['password'])) {
    if ($_POST['password'] === $adminPassword) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
        header('Location: admin.php');
        exit;
    }

    $loginError = '密码错误';
}

$sessionTimeout = (int)($adminConfig['session_timeout'] ?? 3600);
if (isset($_SESSION['admin_login_time']) && (time() - (int)$_SESSION['admin_login_time']) > $sessionTimeout) {
    unset($_SESSION['admin_logged_in'], $_SESSION['admin_login_time']);
}

$cashierName = (string)$db->getConfig('cashier_name', '云盘支付收银台');

if (!isset($_SESSION['admin_logged_in'])) {
    $loginState = [
        'cashierName' => $cashierName,
        'loginError' => $loginError,
    ];
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台登录 - <?php echo htmlspecialchars($cashierName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/common.css">
    <style>
        [v-cloak] { display: none; }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #0f172a 0%, #1e293b 45%, #334155 100%);
        }

        .login-shell {
            width: 100%;
            max-width: 430px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 24px 40px rgba(0, 0, 0, 0.25);
        }

        .login-title {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
            text-align: center;
        }

        .login-subtitle {
            text-align: center;
            color: #64748b;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div id="admin-login-app" class="login-shell" v-cloak>
        <div class="login-title">管理后台登录</div>
        <div class="login-subtitle" v-text="state.cashierName"></div>

        <div v-if="state.loginError" class="error" v-text="state.loginError"></div>
        <div v-if="clientError" class="error" v-text="clientError"></div>

        <form method="post" @submit="handleSubmit">
            <div class="form-group">
                <label for="password">管理员密码</label>
                <input id="password" name="password" type="password" v-model.trim="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="login-btn" :disabled="submitting">
                {{ submitting ? '登录中...' : '登录' }}
            </button>
        </form>
    </div>

    <script>
        window.__ADMIN_PAGE__ = 'login';
        window.__ADMIN_LOGIN_STATE__ = <?php echo json_encode($loginState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="assets/js/admin-app.js?v=0.0.1"></script>
</body>
</html>
    <?php
    exit;
}

$successMessage = '';
$errorMessage = '';

if (isset($_POST['clean_expired'])) {
    try {
        $db->cleanExpiredOrders();
        $successMessage = '过期订单清理完成';
    } catch (Exception $e) {
        $errorMessage = '清理失败：' . $e->getMessage();
    }
}

try {
    $stats = $db->getOrderStats();
} catch (Exception $e) {
    $stats = [
        'total_orders' => 0,
        'paid_orders' => 0,
        'pending_orders' => 0,
        'total_amount' => 0,
    ];
    $errorMessage = $errorMessage ?: $e->getMessage();
}

$uiConfig = $db->getConfig('ui_config', []);
$paymentConfig = $db->getConfig('payment_config', []);
$currencySymbol = $paymentConfig['currency_symbols']['CNY'] ?? '¥';
$amountPrecision = isset($paymentConfig['amount_precision']) ? (int)$paymentConfig['amount_precision'] : 2;

$adminState = [
    'cashierName' => $cashierName,
    'stats' => [
        'totalOrders' => (int)($stats['total_orders'] ?? 0),
        'paidOrders' => (int)($stats['paid_orders'] ?? 0),
        'pendingOrders' => (int)($stats['pending_orders'] ?? 0),
        'formattedTotalAmount' => $currencySymbol . number_format(((int)($stats['total_amount'] ?? 0)) / 100, $amountPrecision),
    ],
    'messages' => [
        'success' => $successMessage,
        'error' => $errorMessage,
    ],
    'links' => [
        'config' => 'config_manager.php',
        'logout' => 'admin.php?logout=1',
    ],
    'csrfToken' => $_SESSION['csrf_token'],
];

$theme = $uiConfig['theme'] ?? [];
$primaryColor = $theme['primary_color'] ?? '#1d4ed8';
$secondaryColor = $theme['secondary_color'] ?? '#0f172a';
$successColor = $theme['success_color'] ?? '#16a34a';
$warningColor = $theme['warning_color'] ?? '#d97706';
$infoColor = $theme['info_color'] ?? '#0284c7';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - <?php echo htmlspecialchars($cashierName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/common.css">
    <style>
        [v-cloak] { display: none; }

        body {
            margin: 0;
            background: #f1f5f9;
            color: #1e293b;
        }

        .header {
            color: #fff;
            padding: 20px;
            background: linear-gradient(135deg, <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?> 0%, <?php echo htmlspecialchars((string)$secondaryColor, ENT_QUOTES, 'UTF-8'); ?> 100%);
            box-shadow: 0 8px 24px rgba(2, 6, 23, 0.18);
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 22px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
            border: 1px solid #e2e8f0;
        }

        .stat-number {
            font-size: 30px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
        }

        .stat-card.total .stat-number { color: <?php echo htmlspecialchars((string)$infoColor, ENT_QUOTES, 'UTF-8'); ?>; }
        .stat-card.paid .stat-number { color: <?php echo htmlspecialchars((string)$successColor, ENT_QUOTES, 'UTF-8'); ?>; }
        .stat-card.pending .stat-number { color: <?php echo htmlspecialchars((string)$warningColor, ENT_QUOTES, 'UTF-8'); ?>; }
        .stat-card.amount .stat-number { color: <?php echo htmlspecialchars((string)$successColor, ENT_QUOTES, 'UTF-8'); ?>; }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .action-btn[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .filters {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .filter-group {
            min-width: 180px;
            flex: 1 1 180px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-group label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
        }

        .filter-group input,
        .filter-group select {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 9px 10px;
            font-size: 14px;
            outline: none;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?>;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .orders-table {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .table-header {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
        }

        .order-count {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
        }

        .table-row {
            display: grid;
            grid-template-columns: 1.2fr 2fr 1fr 1fr 1fr 1.2fr;
            gap: 12px;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .table-row.header-row {
            font-weight: 700;
            background: #f8fafc;
            color: #334155;
        }

        .table-row:last-child {
            border-bottom: none;
        }

        .loading,
        .empty,
        .error-block {
            padding: 36px 12px;
            text-align: center;
            color: #64748b;
        }

        .error-block {
            color: #b91c1c;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            border-top: 1px solid #e2e8f0;
        }

        .page-btn {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 13px;
        }

        .page-btn.active {
            color: #fff;
            border-color: <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?>;
            background: <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?>;
        }

        .page-btn[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            color: #64748b;
            font-size: 13px;
            margin-left: 8px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 64px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        @media (max-width: 900px) {
            .table-row {
                grid-template-columns: 1fr;
                gap: 6px;
            }

            .table-row.header-row {
                display: none;
            }

            .table-row span::before {
                content: attr(data-label) ': ';
                font-weight: 700;
                color: #64748b;
            }
        }
    </style>
</head>
<body>
    <div id="admin-dashboard-app" v-cloak>
        <div class="header">
            <h1>{{ state.cashierName }} - 管理后台</h1>
        </div>

        <div class="container">
            <div v-if="state.messages.success" class="alert alert-success">{{ state.messages.success }}</div>
            <div v-if="state.messages.error" class="alert alert-danger">{{ state.messages.error }}</div>
            <div v-if="runtimeMessage.text" :class="runtimeMessage.type === 'error' ? 'alert alert-danger' : 'alert alert-success'">
                {{ runtimeMessage.text }}
            </div>

            <div class="stats">
                <div class="stat-card total">
                    <div class="stat-number">{{ state.stats.totalOrders }}</div>
                    <div class="stat-label">总订单数</div>
                </div>
                <div class="stat-card paid">
                    <div class="stat-number">{{ state.stats.paidOrders }}</div>
                    <div class="stat-label">已支付订单</div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-number">{{ state.stats.pendingOrders }}</div>
                    <div class="stat-label">待支付订单</div>
                </div>
                <div class="stat-card amount">
                    <div class="stat-number">{{ state.stats.formattedTotalAmount }}</div>
                    <div class="stat-label">总收入</div>
                </div>
            </div>

            <div class="actions">
                <button class="btn action-btn" @click="cleanExpiredOrders" :disabled="cleaning">
                    {{ cleaning ? '清理中...' : '清理过期订单' }}
                </button>
                <button class="btn action-btn" @click="refreshAll" :disabled="loadingOrders">
                    {{ loadingOrders ? '刷新中...' : '刷新数据' }}
                </button>
                <a class="btn" :href="state.links.config">配置管理</a>
                <a class="btn btn-secondary" :href="state.links.logout">退出登录</a>
            </div>

            <div class="filters">
                <div class="filter-group">
                    <label for="statusFilter">订单状态</label>
                    <select id="statusFilter" v-model="filters.status" @change="onFilterChanged">
                        <option value="">全部状态</option>
                        <option value="pending">待支付</option>
                        <option value="processing">处理中</option>
                        <option value="paid">已支付</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="searchInput">搜索订单</label>
                    <input id="searchInput" type="text" v-model.trim="filters.search" @input="onSearchChanged" placeholder="订单号或商品名称">
                </div>
                <div class="filter-group">
                    <label for="limitSelect">每页显示</label>
                    <select id="limitSelect" v-model.number="filters.limit" @change="onFilterChanged">
                        <option :value="10">10 条</option>
                        <option :value="20">20 条</option>
                        <option :value="50">50 条</option>
                        <option :value="100">100 条</option>
                    </select>
                </div>
            </div>

            <div class="orders-table">
                <div class="table-header">
                    <span>订单列表</span>
                    <span class="order-count">{{ orderCountText }}</span>
                </div>

                <div class="table-row header-row" v-if="orders.length > 0">
                    <span>订单号</span>
                    <span>商品名称</span>
                    <span>金额</span>
                    <span>状态</span>
                    <span>支付方式</span>
                    <span>创建时间</span>
                </div>

                <div v-if="loadingOrders" class="loading">正在加载订单数据...</div>
                <div v-else-if="loadError" class="error-block">加载失败：{{ loadError }}</div>
                <div v-else-if="orders.length === 0" class="empty">暂无订单数据</div>

                <div v-for="order in orders" :key="order.id" class="table-row">
                    <span data-label="订单号">{{ order.order_no }}</span>
                    <span data-label="商品名称">{{ order.name }}</span>
                    <span data-label="金额">{{ order.formatted_amount }}</span>
                    <span data-label="状态"><span class="status" :class="order.status">{{ order.status_text }}</span></span>
                    <span data-label="支付方式">{{ order.payment_type || '-' }}</span>
                    <span data-label="创建时间">{{ order.formatted_created_at }}</span>
                </div>

                <div class="pagination" v-if="pagination.total_pages > 1">
                    <button class="page-btn" @click="previousPage" :disabled="!pagination.has_prev">上一页</button>
                    <button
                        v-for="page in pageNumbers"
                        :key="page"
                        class="page-btn"
                        :class="{ active: page === pagination.current_page }"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </button>
                    <button class="page-btn" @click="nextPage" :disabled="!pagination.has_next">下一页</button>
                    <span class="pagination-info">第 {{ pagination.current_page }} / {{ pagination.total_pages }} 页</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.__ADMIN_PAGE__ = 'dashboard';
        window.__ADMIN_STATE__ = <?php echo json_encode($adminState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="assets/js/admin-app.js?v=0.0.1"></script>
</body>
</html>
