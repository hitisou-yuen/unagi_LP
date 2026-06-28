七宗遊園 うなぎ弁当 Stripe Checkout PHP一式 修正版

【重要な変更】
この修正版では、カード決済画面へ移動しただけの注文は orders/ に保存しません。
決済前の一時データは pending_orders/ に保存し、決済成功を確認できた注文だけ orders/ に移動します。
途中離脱・キャンセルした注文は確定注文に入りません。

【入っているファイル】
- index.html
- config.php
- functions.php
- create-checkout-session.php
- success.php
- cancel.php
- stripe-webhook.php
- orders/.htaccess
- pending_orders/.htaccess
- README.txt

【設置場所】
/public_html/bento/ に上書きアップロードしてください。

【最初に変更するファイル】
config.php を開いて、以下を変更してください。

1. STRIPE_SECRET_KEY
Stripeサンドボックスのシークレットキーを貼り付けます。
例: sk_test_xxxxxxxxxxxxxxxxx

2. BASE_URL
LPを設置するURLに変更します。最後の / は不要です。
例: https://hitisou-yuen.com/bento

3. ORDER_NOTICE_EMAIL
注文通知メールを受け取りたいメールアドレスを入れます。不要なら空欄のままでOKです。

4. STRIPE_WEBHOOK_SECRET
Webhookを設定したら whsec_... を入れます。最初のテストでは空欄でも動きます。

【GitHubに上げないもの】
- config.php
- orders/
- pending_orders/
- .DS_Store
- *.log

【動作確認】
1. カード決済を途中でキャンセルする
   → cancel.phpに戻る
   → orders/ にjsonが増えない

2. カード決済を最後まで成功させる
   → success.phpに戻る
   → orders/ にpaidのjsonが保存される

3. pending_orders/ は一時保存用
   → 決済成功後は削除される
   → 途中離脱で残る場合がありますが、確定注文ではありません
