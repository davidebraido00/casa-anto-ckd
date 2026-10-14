<?php
require_once __DIR__ . '/ShopBase.php';

/**
 * Integrazioni di pagamento via REST (cURL, nessuna dipendenza Composer).
 *
 * Stripe Checkout: carte, Apple Pay, Google Pay (metodi attivati dalla
 *   dashboard Stripe). Chiavi in settings.json → stripe.
 * PayPal Orders v2: create + capture. Chiavi in settings.json → paypal.
 */
class ShopPayments {

    // --- Stripe -------------------------------------------------------------

    private static function stripe($method, $path, $params = []) {
        $key = ShopBase::setting('stripe', 'secret_key');
        if (!$key) throw new ShopException('Stripe non configurato.', 'payment.not_configured');
        $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
        $ch = curl_init();
        if ($method === 'GET' && $params) $url .= '?' . http_build_query($params);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $key . ':',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new ShopException('Stripe non raggiungibile: ' . $err, 'payment.network');
        $json = json_decode($body, true);
        if ($code >= 400) {
            throw new ShopException('Stripe: ' . ($json['error']['message'] ?? ('HTTP ' . $code)), 'payment.stripe');
        }
        return $json;
    }

    private static function cents($v) {
        return (int)round((float)$v * 100);
    }

    /**
     * Crea una sessione Stripe Checkout per l'ordine.
     * @return array {id, url}
     */
    public static function stripeCreateSession($order, $items, $successUrl, $cancelUrl) {
        $currency = ShopBase::setting('shop', 'currency', 'eur');
        $params = [
            'mode' => 'payment',
            'customer_email' => $order['email'],
            'client_reference_id' => (string)$order['id'],
            'locale' => $order['language'] === 'en' ? 'en' : 'it',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['order_id' => $order['id'], 'order_number' => $order['number']],
            'payment_intent_data' => [
                'description' => 'Ordine ' . $order['number'],
                'metadata' => ['order_id' => $order['id'], 'order_number' => $order['number']],
            ],
            'line_items' => [],
        ];
        foreach ($items as $i => $it) {
            $params['line_items'][$i] = [
                'quantity' => (int)$it['quantity'],
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => self::cents($it['unitPrice']),
                    'product_data' => ['name' => $it['productName'] . ' – ' . $it['format']],
                ],
            ];
        }
        $params['shipping_options'] = [[
            'shipping_rate_data' => [
                'type' => 'fixed_amount',
                'display_name' => $order['language'] === 'en' ? 'Shipping' : 'Spedizione',
                'fixed_amount' => ['amount' => self::cents($order['shippingCost']), 'currency' => $currency],
            ],
        ]];
        if ((float)$order['discountAmount'] > 0) {
            // coupon "usa e getta" con l'importo esatto calcolato dal server
            $coupon = self::stripe('POST', 'coupons', [
                'amount_off' => self::cents($order['discountAmount']),
                'currency' => $currency,
                'duration' => 'once',
                'max_redemptions' => 1,
                'name' => $order['discountCode'] ?: 'Sconto',
            ]);
            $params['discounts'] = [['coupon' => $coupon['id']]];
        }
        $session = self::stripe('POST', 'checkout/sessions', $params);
        return ['id' => $session['id'], 'url' => $session['url']];
    }

    /**
     * Legge la sessione. @return array {paid, amount, ref, fee, orderId}
     */
    public static function stripeGetSession($sessionId) {
        $s = self::stripe('GET', 'checkout/sessions/' . rawurlencode($sessionId), [
            'expand' => ['payment_intent.latest_charge.balance_transaction'],
        ]);
        $fee = null;
        $pi = $s['payment_intent'] ?? null;
        if (is_array($pi)) {
            $bt = $pi['latest_charge']['balance_transaction'] ?? null;
            if (is_array($bt) && isset($bt['fee'])) $fee = $bt['fee'] / 100;
        }
        return [
            'paid' => ($s['payment_status'] ?? '') === 'paid',
            'amount' => isset($s['amount_total']) ? $s['amount_total'] / 100 : null,
            'ref' => is_array($pi) ? $pi['id'] : ($pi ?: $s['id']),
            'fee' => $fee,
            'orderId' => (int)($s['metadata']['order_id'] ?? $s['client_reference_id'] ?? 0),
            'sessionId' => $s['id'],
        ];
    }

    /** Verifica la firma del webhook Stripe. @return array evento */
    public static function stripeVerifyWebhook($payload, $header) {
        $secret = ShopBase::setting('stripe', 'webhook_secret');
        if (!$secret) throw new ShopException('Webhook secret mancante.', 'webhook.not_configured');
        $ts = null; $sigs = [];
        foreach (explode(',', (string)$header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) continue;
            if ($kv[0] === 't') $ts = $kv[1];
            if ($kv[0] === 'v1') $sigs[] = $kv[1];
        }
        if (!$ts || !$sigs) throw new ShopException('Firma mancante.', 'webhook.signature');
        if (abs(time() - (int)$ts) > 300) throw new ShopException('Firma scaduta.', 'webhook.signature');
        $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) return json_decode($payload, true);
        }
        throw new ShopException('Firma non valida.', 'webhook.signature');
    }

    // --- PayPal -------------------------------------------------------------

    private static function paypalBase() {
        return ShopBase::setting('paypal', 'mode', 'sandbox') === 'live'
            ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private static function paypalToken() {
        $id = ShopBase::setting('paypal', 'client_id');
        $secret = ShopBase::setting('paypal', 'client_secret');
        if (!$id || !$secret) throw new ShopException('PayPal non configurato.', 'payment.not_configured');
        $ch = curl_init(self::paypalBase() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $id . ':' . $secret,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$body, true);
        if ($code >= 400 || empty($json['access_token'])) throw new ShopException('PayPal: autenticazione fallita.', 'payment.paypal');
        return $json['access_token'];
    }

    private static function paypal($method, $path, $payload = null) {
        $token = self::paypalToken();
        $ch = curl_init(self::paypalBase() . $path);
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $token];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload === null ? '{}' : json_encode($payload));
            $headers[] = 'Prefer: return=representation';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$body, true);
        if ($code >= 400) {
            $msg = $json['details'][0]['description'] ?? $json['message'] ?? ('HTTP ' . $code);
            $issue = $json['details'][0]['issue'] ?? null;
            throw new ShopException('PayPal: ' . $msg, $issue === 'ORDER_ALREADY_CAPTURED' ? 'payment.already_captured' : 'payment.paypal');
        }
        return $json;
    }

    private static function amount($v) {
        return number_format((float)$v, 2, '.', '');
    }

    /** @return array {id, url} */
    public static function paypalCreateOrder($order, $items, $returnUrl, $cancelUrl) {
        $cur = strtoupper(ShopBase::setting('shop', 'currency', 'eur'));
        $ppItems = [];
        foreach ($items as $it) {
            $ppItems[] = [
                'name' => mb_substr($it['productName'] . ' – ' . $it['format'], 0, 127),
                'sku' => $it['sku'],
                'quantity' => (string)(int)$it['quantity'],
                'unit_amount' => ['currency_code' => $cur, 'value' => self::amount($it['unitPrice'])],
                'category' => 'PHYSICAL_GOODS',
            ];
        }
        $breakdown = [
            'item_total' => ['currency_code' => $cur, 'value' => self::amount($order['subtotal'])],
            'shipping' => ['currency_code' => $cur, 'value' => self::amount($order['shippingCost'])],
        ];
        if ((float)$order['discountAmount'] > 0) {
            $breakdown['discount'] = ['currency_code' => $cur, 'value' => self::amount($order['discountAmount'])];
        }
        $res = self::paypal('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order['number'],
                'invoice_id' => $order['number'],
                'custom_id' => (string)$order['id'],
                'description' => 'Casa Anto – ' . $order['number'],
                'amount' => ['currency_code' => $cur, 'value' => self::amount($order['total']), 'breakdown' => $breakdown],
                'items' => $ppItems,
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => 'Casa Anto',
                'locale' => $order['language'] === 'en' ? 'en-GB' : 'it-IT',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]]],
        ]);
        $url = null;
        foreach ($res['links'] ?? [] as $l) {
            if (in_array($l['rel'], ['payer-action', 'approve'], true)) $url = $l['href'];
        }
        if (!$url) throw new ShopException('PayPal: link di approvazione mancante.', 'payment.paypal');
        return ['id' => $res['id'], 'url' => $url];
    }

    /** @return array {paid, amount, ref, fee, orderId} */
    public static function paypalCapture($paypalOrderId) {
        try {
            $res = self::paypal('POST', '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture');
        } catch (ShopException $e) {
            if ($e->errorCode !== 'payment.already_captured') throw $e;
            $res = self::paypal('GET', '/v2/checkout/orders/' . rawurlencode($paypalOrderId));
        }
        $unit = $res['purchase_units'][0] ?? [];
        $cap = $unit['payments']['captures'][0] ?? null;
        return [
            'paid' => ($res['status'] ?? '') === 'COMPLETED' && $cap && ($cap['status'] ?? '') === 'COMPLETED',
            'amount' => $cap ? (float)$cap['amount']['value'] : null,
            'ref' => $cap['id'] ?? $paypalOrderId,
            'fee' => isset($cap['seller_receivable_breakdown']['paypal_fee']['value']) ? (float)$cap['seller_receivable_breakdown']['paypal_fee']['value'] : null,
            'orderId' => (int)($cap['custom_id'] ?? $unit['custom_id'] ?? 0),
        ];
    }
}
