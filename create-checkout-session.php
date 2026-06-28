<?php
/**
 * Stripe Checkout Session 作成用エンドポイント
 *
 * LPの「カードで事前決済」ボタンからPOSTされます。
 * ブラウザから送られた金額は信用せず、config.phpの価格表だけを使います。
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'POSTでアクセスしてください。'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    json_response(['error' => '注文データを読み取れませんでした。'], 400);
}

try {
    $incomingItems = $payload['items'] ?? [];
    if (!is_array($incomingItems)) {
        throw new InvalidArgumentException('注文商品が不正です。');
    }

    $items = [];
    $lineItems = [];
    $bentoCount = 0;
    $total = 0;

    foreach ($incomingItems as $incomingItem) {
        $productId = (string)($incomingItem['productId'] ?? '');
        $quantity = (int)($incomingItem['quantity'] ?? 0);

        if ($quantity <= 0) continue;
        if ($quantity > 30) {
            throw new InvalidArgumentException('一度に注文できる数量を超えています。');
        }
        if (!isset(PRODUCT_CATALOG[$productId])) {
            throw new InvalidArgumentException('未登録の商品が含まれています。');
        }

        $product = PRODUCT_CATALOG[$productId];
        $items[] = [
            'product_id' => $productId,
            'name' => $product['name'],
            'quantity' => $quantity,
            'unit_price' => $product['price'],
            'subtotal' => $product['price'] * $quantity,
        ];

        $lineItems[] = [
            'price' => $product['price_id'],
            'quantity' => $quantity,
        ];

        $bentoCount += $quantity;
        $total += $product['price'] * $quantity;
    }

    if ($bentoCount < 2) {
        throw new InvalidArgumentException('お弁当は合計2個以上でご注文ください。');
    }

    $customer = $payload['customer'] ?? [];
    $delivery = $payload['delivery'] ?? [];

    $name = trim((string)($customer['name'] ?? ''));
    $phone = trim((string)($customer['phone'] ?? ''));
    $zip = trim((string)($customer['zip'] ?? ''));
    $address = trim((string)($customer['address'] ?? ''));
    $deliveryDate = trim((string)($delivery['date'] ?? ''));
    $deliveryDateText = trim((string)($delivery['dateText'] ?? ''));
    $note = trim((string)($payload['note'] ?? ''));

    if ($name === '' || $phone === '' || $address === '' || $deliveryDate === '') {
        throw new InvalidArgumentException('お名前・電話番号・住所・配達希望日は必須です。');
    }

    $orderId = generate_order_id();

    $order = [
        'order_id' => $orderId,
        'status' => 'checkout_created',
        'created_at' => date('c'),
        'items' => $items,
        'bento_count' => $bentoCount,
        'total' => $total,
        'customer' => [
            'name' => $name,
            'phone' => $phone,
            'zip' => $zip,
            'address' => $address,
        ],
        'delivery' => [
            'date' => $deliveryDate,
            'date_text' => $deliveryDateText !== '' ? $deliveryDateText : $deliveryDate,
        ],
        'note' => $note !== '' ? $note : 'なし',
    ];

    save_pending_order($order);

    $sessionParams = [
        'mode' => 'payment',
        'success_url' => BASE_URL . '/success.php?session_id={CHECKOUT_SESSION_ID}&order_id=' . rawurlencode($orderId),
        'cancel_url' => BASE_URL . '/cancel.php?order_id=' . rawurlencode($orderId),
        'client_reference_id' => $orderId,
        'customer_creation' => 'if_required',
        'phone_number_collection[enabled]' => 'true',
        'metadata[order_id]' => $orderId,
        'metadata[customer_name]' => mb_substr($name, 0, 80),
        'metadata[phone]' => mb_substr($phone, 0, 40),
        'metadata[delivery_date]' => mb_substr($deliveryDateText !== '' ? $deliveryDateText : $deliveryDate, 0, 80),
        'metadata[total]' => (string)$total,
    ];

    foreach ($lineItems as $index => $lineItem) {
        $sessionParams["line_items[$index][price]"] = $lineItem['price'];
        $sessionParams["line_items[$index][quantity]"] = (string)$lineItem['quantity'];
    }

    $session = stripe_api_request('POST', 'checkout/sessions', $sessionParams);

    $order['stripe_session_id'] = $session['id'] ?? '';
    $order['status'] = 'checkout_redirected';
    save_pending_order($order);

    json_response([
        'url' => $session['url'] ?? null,
        'order_id' => $orderId,
    ]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}
