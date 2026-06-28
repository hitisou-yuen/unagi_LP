<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$orderId = isset($_GET['order_id']) ? (string)$_GET['order_id'] : '';
$sessionId = isset($_GET['session_id']) ? (string)$_GET['session_id'] : '';
$order = $orderId !== '' ? load_order($orderId) : null;
$pendingOrder = $orderId !== '' ? load_pending_order($orderId) : null;
$errorMessage = '';

try {
    if (!$order && $pendingOrder && $sessionId !== '') {
        $session = stripe_api_request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
        $sessionOrderId = $session['client_reference_id'] ?? ($session['metadata']['order_id'] ?? '');
        $paymentStatus = $session['payment_status'] ?? '';

        if ($sessionOrderId === $orderId && $paymentStatus === 'paid') {
            $pendingOrder['status'] = 'paid';
            $pendingOrder['paid_at'] = date('c');
            $pendingOrder['stripe_session_id'] = $session['id'] ?? $sessionId;
            $pendingOrder['stripe_payment_status'] = $paymentStatus;
            save_order($pendingOrder);
            append_order_csv($pendingOrder);
            send_order_email($pendingOrder);
            delete_pending_order($orderId);
            $order = $pendingOrder;
        } else {
            $errorMessage = '決済完了を確認できませんでした。';
        }
    }
} catch (Throwable $e) {
    $errorMessage = '決済情報の確認中にエラーが発生しました。お電話でご連絡ください。';
}

$lineMessage = $order ? build_line_message($order, 'カード決済済み') : '';
$lineUrl = $lineMessage !== ''
    ? 'https://line.me/R/oaMessage/' . rawurlencode(LINE_ACCOUNT_ID) . '/?' . rawurlencode($lineMessage)
    : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>決済完了｜七宗遊園</title>
  <style>
    body { margin:0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Noto Sans JP", sans-serif; background:#fbf3e4; color:#24170f; line-height:1.8; }
    .wrap { width:min(100% - 32px, 480px); margin:0 auto; padding:42px 0; }
    .card { background:#fffaf0; border:1px solid rgba(107,68,35,.15); border-radius:24px; padding:24px; box-shadow:0 14px 36px rgba(59,37,21,.14); }
    h1 { font-family: serif; color:#4a2f1b; line-height:1.4; margin:0 0 16px; font-size:28px; }
    .btn { display:flex; align-items:center; justify-content:center; min-height:58px; border-radius:16px; background:#06c755; color:white; font-weight:900; text-decoration:none; font-size:18px; margin-top:18px; }
    .note { color:#6f5a46; font-size:14px; font-weight:700; }
    .box { white-space:pre-line; background:#fff7e8; border:1px dashed rgba(107,68,35,.35); border-radius:16px; padding:14px; font-size:14px; margin-top:18px; }
    .error { background:#fff1f1; border:1px solid rgba(185,28,28,.22); color:#b91c1c; border-radius:16px; padding:14px; font-weight:900; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <?php if ($order): ?>
        <h1>カード決済が完了しました。</h1>
        <p>ご注文内容を保存しました。下のボタンからLINEを開き、念のため注文内容を七宗遊園へ送信してください。</p>
        <p class="note">カード情報はStripeで処理されており、七宗遊園ではカード番号を確認・保存しません。</p>
        <?php if ($lineUrl !== ''): ?>
          <a class="btn" href="<?= htmlspecialchars($lineUrl, ENT_QUOTES, 'UTF-8') ?>">LINEで注文内容を送る</a>
          <div class="box"><?= htmlspecialchars($lineMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      <?php else: ?>
        <h1>決済確認中です。</h1>
        <div class="error"><?= htmlspecialchars($errorMessage !== '' ? $errorMessage : '注文情報を確認できませんでした。', ENT_QUOTES, 'UTF-8') ?></div>
        <p>恐れ入りますが、お電話でご連絡ください。</p>
        <p><a href="tel:0574461128">0574-46-1128</a></p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
