<?php
/**
 * Checkout page (Vue-powered frontend)
 */

declare(strict_types=1);

require_once 'includes/Database.php';
require_once 'includes/Logger.php';
require_once 'includes/UserAgent.php';

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

$paymentMethods = $db->getConfig('payment_methods', []);
$paymentCompatibility = $db->getConfig('payment_compatibility', []);
$environmentRecommendations = $db->getConfig('environment_recommendations', []);
$autoRedirectConfig = $db->getConfig('auto_redirect_config', []);
$uiConfig = $db->getConfig('ui_config', []);
$paymentConfig = $db->getConfig('payment_config', []);

$orderNo = $_GET['order_no'] ?? '';
if ($orderNo === '') {
    http_response_code(400);
    echo '订单号不能为空';
    exit;
}

try {
    $order = $db->getOrderByNo($orderNo);

    if (!$order) {
        http_response_code(404);
        echo '订单不存在';
        exit;
    }

    if (($order['status'] ?? '') === 'paid') {
        echo '<script>alert("订单已支付"); window.close();</script>';
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo '系统错误';
    exit;
}

$environment = UserAgent::detectEnvironment();

$autoRedirectEnabled = is_array($autoRedirectConfig) && !empty($autoRedirectConfig['enabled']);
$autoRedirectMap = (is_array($autoRedirectConfig) && isset($autoRedirectConfig['environments']) && is_array($autoRedirectConfig['environments']))
    ? $autoRedirectConfig['environments']
    : [];

if ($autoRedirectEnabled && isset($autoRedirectMap[$environment])) {
    $autoPaymentType = $autoRedirectMap[$environment];

    if (isset($paymentMethods[$autoPaymentType]) && !empty($paymentMethods[$autoPaymentType]['enabled'])) {
        $redirectUrl = 'process_payment.php?' . http_build_query([
            'order_no' => $orderNo,
            'payment_type' => $autoPaymentType,
        ]);

        Logger::info('自动跳转支付', [
            'order_no' => $orderNo,
            'environment' => $environment,
            'payment_type' => $autoPaymentType,
        ]);

        header('Location: ' . $redirectUrl);
        exit;
    }
}

$recommendedPayment = $environmentRecommendations[$environment] ?? 'alipay';

$availablePayments = [];
if (is_array($paymentMethods)) {
    foreach ($paymentMethods as $type => $method) {
        if (empty($method['enabled'])) {
            continue;
        }

        $methodData = [
            'type' => (string)$type,
            'name' => (string)($method['name'] ?? $type),
            'icon' => (string)($method['icon'] ?? '💳'),
            'description' => (string)($method['description'] ?? ''),
            'warning' => false,
        ];

        $compatible = $paymentCompatibility[$type] ?? [];
        if (!in_array($environment, $compatible, true) && !in_array('desktop', $compatible, true)) {
            $methodData['warning'] = true;
        }

        $availablePayments[] = $methodData;
    }
}

$showPaymentMethods = !empty($availablePayments);
$errorMessage = $showPaymentMethods ? '' : '当前环境不支持任何支付方式';

$supportedPaymentNames = [];
if (is_array($paymentMethods)) {
    foreach ($paymentMethods as $method) {
        if (!empty($method['enabled']) && !empty($method['name'])) {
            $supportedPaymentNames[] = (string)$method['name'];
        }
    }
}

$cashierName = $db->getConfig('cashier_name', '云盘支付收银台');
$environmentDescription = UserAgent::getEnvironmentDescription();
$currencySymbol = $paymentConfig['currency_symbols'][$order['currency'] ?? 'CNY'] ?? '¥';
$amountPrecision = isset($paymentConfig['amount_precision']) ? (int)$paymentConfig['amount_precision'] : 2;

$checkoutState = [
    'cashierName' => $cashierName,
    'subtitle' => '请选择支付方式完成支付',
    'environmentDescription' => $environmentDescription,
    'showPaymentMethods' => $showPaymentMethods,
    'errorMessage' => $errorMessage,
    'supportedPaymentNames' => $supportedPaymentNames,
    'recommendedPayment' => (string)$recommendedPayment,
    'order' => [
        'name' => (string)($order['name'] ?? ''),
        'orderNo' => (string)($order['order_no'] ?? ''),
        'formattedAmount' => $currencySymbol . number_format(((int)($order['amount'] ?? 0)) / 100, $amountPrecision),
    ],
    'payments' => $availablePayments,
    'redirectBaseUrl' => 'process_payment.php',
];

$theme = $uiConfig['theme'] ?? [];
$layout = $uiConfig['layout'] ?? [];
$paymentGrid = $uiConfig['payment_grid'] ?? [];

$primaryColor = $theme['primary_color'] ?? '#a0aec0';
$secondaryColor = $theme['secondary_color'] ?? '#718096';
$successColor = $theme['success_color'] ?? '#48bb78';
$maxWidth = $layout['max_width'] ?? '500px';
$borderRadius = $layout['border_radius'] ?? '20px';
$boxShadow = $layout['box_shadow'] ?? '0 20px 40px rgba(0, 0, 0, 0.1)';
$gridColumns = max(1, (int)($paymentGrid['columns'] ?? 2));
$mobileColumns = max(1, (int)($paymentGrid['mobile_columns'] ?? 1));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string)$cashierName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/common.css">
    <style>
        [v-cloak] { display: none; }

        body {
            background: linear-gradient(135deg, <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?> 0%, <?php echo htmlspecialchars((string)$secondaryColor, ENT_QUOTES, 'UTF-8'); ?> 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .checkout-container {
            background: #fff;
            border-radius: <?php echo htmlspecialchars((string)$borderRadius, ENT_QUOTES, 'UTF-8'); ?>;
            padding: 40px;
            box-shadow: <?php echo htmlspecialchars((string)$boxShadow, ENT_QUOTES, 'UTF-8'); ?>;
            max-width: <?php echo htmlspecialchars((string)$maxWidth, ENT_QUOTES, 'UTF-8'); ?>;
            width: 100%;
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #6b7280;
            font-size: 15px;
        }

        .order-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .order-item:last-child {
            margin-bottom: 0;
        }

        .order-label {
            color: #64748b;
            font-weight: 500;
        }

        .order-value {
            color: #0f172a;
            font-weight: 600;
            text-align: right;
            word-break: break-all;
        }

        .amount {
            color: <?php echo htmlspecialchars((string)$successColor, ENT_QUOTES, 'UTF-8'); ?>;
            font-size: 24px;
        }

        .environment-info {
            background: #e8f4fd;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .payment-title {
            margin-bottom: 12px;
            color: #1f2937;
            font-size: 17px;
            font-weight: 700;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: repeat(<?php echo $gridColumns; ?>, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .payment-method {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            position: relative;
            transition: 0.25s ease;
        }

        .payment-method:hover {
            border-color: <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?>;
            transform: translateY(-1px);
        }

        .payment-method.recommended {
            border-color: <?php echo htmlspecialchars((string)$successColor, ENT_QUOTES, 'UTF-8'); ?>;
            background: #f0fdf4;
        }

        .payment-method.warning {
            border-color: #f59e0b;
            background: #fffbeb;
        }

        .payment-method.selected {
            border-color: <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?>;
            background: #eff6ff;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.12);
        }

        .payment-icon {
            font-size: 28px;
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
        }

        .payment-icon i {
            font-size: 28px;
        }

        .payment-name {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .payment-description {
            color: #64748b;
            font-size: 13px;
            line-height: 1.45;
        }

        .warning-text {
            color: #b45309;
            margin-top: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .pay-btn {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 12px 16px;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
            background: linear-gradient(135deg, <?php echo htmlspecialchars((string)$primaryColor, ENT_QUOTES, 'UTF-8'); ?> 0%, <?php echo htmlspecialchars((string)$secondaryColor, ENT_QUOTES, 'UTF-8'); ?> 100%);
        }

        .pay-btn:disabled {
            cursor: not-allowed;
            opacity: 0.65;
        }

        .pay-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(0, 0, 0, 0.15);
        }

        .error-message {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 16px;
            line-height: 1.45;
        }

        @media (max-width: 768px) {
            .checkout-container {
                padding: 22px;
            }

            .payment-grid {
                grid-template-columns: repeat(<?php echo $mobileColumns; ?>, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <div id="checkout-app" class="checkout-container" v-cloak>
        <div class="header">
            <div class="title" v-text="state.cashierName"></div>
            <div class="subtitle" v-text="state.subtitle"></div>
        </div>

        <div class="order-info">
            <div class="order-item">
                <span class="order-label">商品名称</span>
                <span class="order-value" v-text="state.order.name"></span>
            </div>
            <div class="order-item">
                <span class="order-label">订单号</span>
                <span class="order-value" v-text="state.order.orderNo"></span>
            </div>
            <div class="order-item">
                <span class="order-label">支付金额</span>
                <span class="order-value amount" v-text="state.order.formattedAmount"></span>
            </div>
        </div>

        <div v-if="state.errorMessage" class="error-message" v-text="state.errorMessage"></div>

        <div class="environment-info">
            检测到您正在使用：<strong v-text="state.environmentDescription"></strong>
        </div>

        <template v-if="state.showPaymentMethods">
            <div class="payment-title">选择支付方式</div>
            <div class="payment-grid">
                <div
                    v-for="method in state.payments"
                    :key="method.type"
                    class="payment-method"
                    :class="{
                        recommended: method.type === state.recommendedPayment,
                        warning: method.warning,
                        selected: selectedPayment === method.type
                    }"
                    @click="selectPayment(method.type)"
                >
                    <div class="payment-icon">
                        <span v-if="resolveIconType(method.icon) === 'text'" v-text="method.icon"></span>
                        <i v-else-if="resolveIconType(method.icon) === 'class'" :class="method.icon"></i>
                        <span v-else v-html="method.icon"></span>
                    </div>
                    <div class="payment-name" v-text="method.name"></div>
                    <div class="payment-description" v-text="method.description"></div>
                    <div v-if="method.warning" class="warning-text">⚠ 当前环境可能不支持该支付方式</div>
                </div>
            </div>

            <button class="pay-btn" :disabled="!selectedPayment" @click="processPayment">
                {{ selectedPayment ? '立即支付' : '请选择支付方式' }}
            </button>
        </template>

        <div v-else class="error-message">
            当前环境不支持任何支付方式。支持的支付方式：{{ supportedPaymentNamesText }}
        </div>
    </div>

    <script>
        window.__CHECKOUT_STATE__ = <?php echo json_encode($checkoutState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="assets/js/checkout-app.js?v=0.0.1"></script>
</body>
</html>
