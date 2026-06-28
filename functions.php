<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
}

function ensure_order_dir(): void
{
    ensure_dir(ORDER_DIR);
}

function ensure_pending_order_dir(): void
{
    ensure_dir(PENDING_ORDER_DIR);
}

function generate_order_id(): string
{
    return 'UY' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
}

function sanitize_order_id(string $orderId): string
{
    return preg_replace('/[^A-Za-z0-9\-]/', '', $orderId) ?: '';
}

function order_file_path(string $orderId, string $dir = ORDER_DIR): string
{
    $safeId = sanitize_order_id($orderId);
    return rtrim($dir, '/') . '/' . $safeId . '.json';
}

function save_order(array $order): void
{
    ensure_order_dir();
    file_put_contents(
        order_file_path((string)$order['order_id'], ORDER_DIR),
        json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function save_pending_order(array $order): void
{
    ensure_pending_order_dir();
    file_put_contents(
        order_file_path((string)$order['order_id'], PENDING_ORDER_DIR),
        json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function load_order(string $orderId): ?array
{
    $path = order_file_path($orderId, ORDER_DIR);
    if (!is_file($path)) {
        return null;
    }
    $json = file_get_contents($path);
    $data = json_decode($json ?: '', true);
    return is_array($data) ? $data : null;
}

function load_pending_order(string $orderId): ?array
{
    $path = order_file_path($orderId, PENDING_ORDER_DIR);
    if (!is_file($path)) {
        return null;
    }
    $json = file_get_contents($path);
    $data = json_decode($json ?: '', true);
    return is_array($data) ? $data : null;
}

function delete_pending_order(string $orderId): void
{
    $path = order_file_path($orderId, PENDING_ORDER_DIR);
    if (is_file($path)) {
        @unlink($path);
    }
}

function append_order_csv(array $order): void
{
    ensure_order_dir();
    $path = ORDER_DIR . '/orders.csv';
    $isNew = !is_file($path);
    $fp = fopen($path, 'ab');
    if (!$fp) return;

    if ($isNew) {
        fputcsv($fp, [
            'created_at', 'order_id', 'status', 'delivery_date', 'customer_name', 'phone', 'zip', 'address', 'items', 'total', 'note', 'stripe_session_id'
        ]);
    }

    $itemsText = '';
    if (!empty($order['items']) && is_array($order['items'])) {
        $itemsText = implode(' / ', array_map(function ($item) {
            return ($item['name'] ?? '') . ' x ' . ($item['quantity'] ?? '');
        }, $order['items']));
    }

    fputcsv($fp, [
        $order['created_at'] ?? '',
        $order['order_id'] ?? '',
        $order['status'] ?? '',
        $order['delivery']['date_text'] ?? ($order['delivery']['date'] ?? ''),
        $order['customer']['name'] ?? '',
        $order['customer']['phone'] ?? '',
        $order['customer']['zip'] ?? '',
        $order['customer']['address'] ?? '',
        $itemsText,
        $order['total'] ?? '',
        $order['note'] ?? '',
        $order['stripe_session_id'] ?? '',
    ]);

    fclose($fp);
}

function build_line_message(array $order, string $paymentMethod = 'カード決済済み'): string
{
    $itemsText = '■ 注文内容: 未選択';
    if (!empty($order['items']) && is_array($order['items'])) {
        $itemsText = implode("\n", array_map(function ($item) {
            return '■ ' . ($item['name'] ?? '') . ': ' . (int)($item['quantity'] ?? 0) . '個';
        }, $order['items']));
    }

    $deliveryText = $order['delivery']['date_text'] ?? ($order['delivery']['date'] ?? '未選択');
    $name = $order['customer']['name'] ?? '未入力';
    $phone = $order['customer']['phone'] ?? '未入力';
    $zip = $order['customer']['zip'] ?? '未入力';
    $address = $order['customer']['address'] ?? '未入力';
    $total = number_format((int)($order['total'] ?? 0));
    $note = $order['note'] ?? 'なし';
    $orderId = $order['order_id'] ?? '';

    return "【七宗遊園 夜専用弁当 予約リクエスト】\n" .
        "■ 注文番号: {$orderId}\n" .
        "■ 配達希望日: {$deliveryText}\n" .
        "■ 代表者名: {$name} 様\n" .
        "■ 電話番号: {$phone}\n" .
        "■ 郵便番号: {$zip}\n" .
        "■ ご住所: {$address}\n\n" .
        "【ご注文内容】\n{$itemsText}\n\n" .
        "■ 合計金額: {$total}円\n" .
        "■ 支払い方法: {$paymentMethod}\n" .
        "■ 備考・ご希望: {$note}\n" .
        "------------------------\n" .
        "※上記の内容で予約をお願いします。";
}

function send_order_email(array $order, string $subjectPrefix = '【七宗遊園】カード決済済み注文'): void
{
    if (ORDER_NOTICE_EMAIL === '') return;

    $subject = $subjectPrefix . ' ' . ($order['order_id'] ?? '');
    $body = build_line_message($order, 'カード決済済み') . "\n\n";
    $body .= "Stripe Session ID: " . ($order['stripe_session_id'] ?? '') . "\n";

    if (function_exists('mb_language')) {
        mb_language('Japanese');
    }
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $headers = "From: no-reply@" . $host . "\r\n";

    if (function_exists('mb_send_mail')) {
        @mb_send_mail(ORDER_NOTICE_EMAIL, $subject, $body, $headers);
    } else {
        @mail(ORDER_NOTICE_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }
}

function stripe_api_request(string $method, string $endpoint, array $params = []): array
{
    if (strpos(STRIPE_SECRET_KEY, 'sk_test_') !== 0 && strpos(STRIPE_SECRET_KEY, 'sk_live_') !== 0 && strpos(STRIPE_SECRET_KEY, 'rk_test_') !== 0 && strpos(STRIPE_SECRET_KEY, 'rk_live_') !== 0) {
        throw new RuntimeException('Stripeのシークレットキーが未設定です。config.phpを確認してください。');
    }

    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($endpoint, '/'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if (!empty($params)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Stripe APIへの接続に失敗しました: ' . $curlError);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Stripe APIのレスポンスを解析できませんでした。');
    }

    if ($httpCode >= 400) {
        $message = $data['error']['message'] ?? 'Stripe APIエラーが発生しました。';
        throw new RuntimeException($message);
    }

    return $data;
}

function verify_stripe_signature(string $payload, string $signatureHeader, string $secret, int $tolerance = 300): bool
{
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
        if ($key === 't') $timestamp = (int)$value;
        if ($key === 'v1' && $value !== null) $signatures[] = $value;
    }

    if (!$timestamp || empty($signatures)) return false;
    if (abs(time() - $timestamp) > $tolerance) return false;

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
