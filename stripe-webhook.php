<?php
/**
 * Stripe Webhook受信用ファイル
 *
 * StripeダッシュボードでWebhook URLとして以下を登録します。
 * https://あなたのドメイン/bento/stripe-webhook.php
 *
 * 受け取るイベント: checkout.session.completed
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (STRIPE_WEBHOOK_SECRET !== '') {
    if (!verify_stripe_signature($payload, $signature, STRIPE_WEBHOOK_SECRET)) {
        http_response_code(400);
        echo 'Invalid signature';
        exit;
    }
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$type = $event['type'] ?? '';

if ($type === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $orderId = $session['metadata']['order_id'] ?? ($session['client_reference_id'] ?? '');
    $paymentStatus = $session['payment_status'] ?? '';

    if ($orderId !== '' && $paymentStatus === 'paid') {
        $existing = load_order((string)$orderId);
        if (!$existing) {
            $order = load_pending_order((string)$orderId);
            if ($order) {
                $order['status'] = 'paid';
                $order['paid_at'] = date('c');
                $order['stripe_session_id'] = $session['id'] ?? ($order['stripe_session_id'] ?? '');
                $order['stripe_payment_status'] = $paymentStatus;
                save_order($order);
                append_order_csv($order);
                send_order_email($order);
                delete_pending_order((string)$orderId);
            }
        }
    }
}

http_response_code(200);
echo 'ok';
