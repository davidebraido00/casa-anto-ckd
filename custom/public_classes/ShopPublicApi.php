<?php
require_once __DIR__ . '/../shop/ShopBase.php';
require_once __DIR__ . '/../shop/ShopCatalog.php';
require_once __DIR__ . '/../shop/ShopCart.php';
require_once __DIR__ . '/../shop/ShopOrders.php';
require_once __DIR__ . '/../shop/ShopPayments.php';
require_once __DIR__ . '/../shop/ShopCustomers.php';
require_once __DIR__ . '/../shop/ShopEngagement.php';

/**
 * API pubbliche del negozio (/public/{action}), chiamate dal sito Next.js
 * lato server. Le chiamate del cliente loggato portano `customerToken` nel
 * body.
 */
class ShopPublicApi {
    protected $db;
    protected $in;

    const ACTIONS = [
        'catalog_products', 'catalog_product', 'shop_info',
        'cart_quote', 'checkout_create', 'checkout_stripe_confirm', 'checkout_paypal_capture', 'checkout_cancel',
        'stripe_webhook', 'order_lookup',
        'customer_register', 'customer_login', 'customer_logout', 'customer_me', 'customer_update',
        'customer_password', 'customer_forgot', 'customer_reset',
        'customer_address_save', 'customer_address_delete', 'customer_orders', 'customer_order',
        'review_submit', 'stock_alert_subscribe',
    ];

    function __construct($db, $parameters) {
        $this->db = $db;
        $this->in = ShopBase::toArray($parameters);
    }

    public static function handles($action) {
        return in_array($action, self::ACTIONS, true);
    }

    public function handle($action) {
        try {
            $method = 'a_' . $action;
            return $this->$method();
        } catch (ShopException $e) {
            $r = ShopBase::fail($e->getMessage(), $e->errorCode);
            if ($e->quote !== null) $r['quote'] = $e->quote;
            return $r;
        } catch (Throwable $e) {
            error_log('[shop] ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return ShopBase::fail('Errore imprevisto, riprova tra poco.', 'shop.internal', 500);
        }
    }

    private function p($key, $default = null) {
        return $this->in[$key] ?? $default;
    }

    private function lang() {
        return ShopBase::lang($this->p('language', 'it'));
    }

    private function customers() { return new ShopCustomers($this->db); }

    private function customerOrNull() {
        return $this->customers()->fromToken($this->p('customerToken'));
    }

    // --- Catalogo ----------------------------------------------------------

    private function a_catalog_products() {
        $c = new ShopCatalog($this->db);
        return ShopBase::ok(['products' => $c->products($this->lang(), (bool)$this->p('featured', false))]);
    }

    private function a_catalog_product() {
        $c = new ShopCatalog($this->db);
        $p = $c->product((string)$this->p('slug'), $this->lang());
        if (!$p) return ShopBase::fail('Prodotto non trovato.', 'product.not_found', 404);
        return ShopBase::ok(['product' => $p]);
    }

    private function a_shop_info() {
        return ShopBase::ok(['shop' => (new ShopCatalog($this->db))->shopInfo($this->lang())]);
    }

    // --- Carrello e checkout ----------------------------------------------

    private function a_cart_quote() {
        $quote = (new ShopCart($this->db))->quote(
            $this->p('items', []), $this->p('country'), $this->p('discountCode'), $this->lang()
        );
        return ShopBase::ok(['quote' => $quote]);
    }

    private function urls($order) {
        $site = ShopBase::siteUrl();
        $lang = $order['language'];
        $q = 'order=' . rawurlencode($order['number']) . '&t=' . rawurlencode($order['accessToken']);
        return [
            'success' => $site . '/' . $lang . '/checkout/conferma?' . $q,
            'cancel' => $site . '/' . $lang . '/checkout?annullato=' . rawurlencode($order['number']) . '&t=' . rawurlencode($order['accessToken']),
        ];
    }

    private function a_checkout_create() {
        $method = $this->p('paymentMethod');
        if (($method === 'stripe' && !ShopBase::setting('stripe', 'secret_key'))
            || ($method === 'paypal' && !ShopBase::setting('paypal', 'client_id'))) {
            throw new ShopException('Metodo di pagamento non disponibile.', 'payment.not_configured');
        }
        $customer = $this->customerOrNull();
        $orders = new ShopOrders($this->db);
        $order = $orders->createFromCheckout($this->in, $customer);
        $items = $orders->items($order['id']);
        $urls = $this->urls($order);

        try {
            if ($order['paymentMethod'] === 'stripe') {
                $s = ShopPayments::stripeCreateSession($order, $items, $urls['success'] . '&session_id={CHECKOUT_SESSION_ID}', $urls['cancel']);
            } else {
                $s = ShopPayments::paypalCreateOrder($order, $items, $urls['success'] . '&provider=paypal', $urls['cancel']);
            }
        } catch (ShopException $e) {
            $orders->update('shop_order', $order['id'], ['status' => 'annullato', 'internalNotes' => 'Creazione pagamento fallita: ' . $e->getMessage()]);
            throw $e;
        }
        $orders->update('shop_order', $order['id'], ['checkoutRef' => $s['id']]);
        return ShopBase::ok(['orderNumber' => $order['number'], 'redirectUrl' => $s['url']]);
    }

    /** Ordine per numero + accessToken (link nelle email e pagina di conferma) */
    private function orderByAccess() {
        $orders = new ShopOrders($this->db);
        $order = $orders->getByNumber((string)$this->p('orderNumber'));
        if (!$order || !hash_equals((string)$order['accessToken'], (string)$this->p('accessToken'))) {
            throw new ShopException('Ordine non trovato.', 'order.not_found');
        }
        return $order;
    }

    private function a_checkout_stripe_confirm() {
        $order = $this->orderByAccess();
        $orders = new ShopOrders($this->db);
        if ($order['status'] === 'in_attesa_pagamento' && $order['paymentMethod'] === 'stripe') {
            $sessionId = (string)($this->p('sessionId') ?: $order['checkoutRef']);
            if ($sessionId !== $order['checkoutRef']) throw new ShopException('Sessione di pagamento non valida.', 'payment.mismatch');
            $s = ShopPayments::stripeGetSession($sessionId);
            if ($s['paid']) $orders->markPaid($order['id'], 'stripe', $s['ref'], $s['fee'], $s['amount']);
            $order = $orders->get($order['id']);
        }
        return ShopBase::ok(['order' => $orders->publicView($order)]);
    }

    private function a_checkout_paypal_capture() {
        $order = $this->orderByAccess();
        $orders = new ShopOrders($this->db);
        if ($order['status'] === 'in_attesa_pagamento' && $order['paymentMethod'] === 'paypal') {
            $ppId = (string)($this->p('paypalOrderId') ?: $order['checkoutRef']);
            if ($ppId !== $order['checkoutRef']) throw new ShopException('Pagamento non valido.', 'payment.mismatch');
            $c = ShopPayments::paypalCapture($ppId);
            if ($c['paid']) $orders->markPaid($order['id'], 'paypal', $c['ref'], $c['fee'], $c['amount']);
            $order = $orders->get($order['id']);
        }
        return ShopBase::ok(['order' => $orders->publicView($order)]);
    }

    /** Il cliente è tornato indietro dal pagamento: l'ordine non pagato viene annullato */
    private function a_checkout_cancel() {
        $orders = new ShopOrders($this->db);
        $order = $this->orderByAccess();
        if ($order['status'] === 'in_attesa_pagamento') {
            $orders->update('shop_order', $order['id'], ['status' => 'annullato', 'internalNotes' => trim($order['internalNotes'] . "\nPagamento annullato dal cliente.")]);
        }
        return ShopBase::ok();
    }

    private function a_order_lookup() {
        $order = $this->orderByAccess();
        return ShopBase::ok(['order' => (new ShopOrders($this->db))->publicView($order)]);
    }

    /** Webhook Stripe: endpoint /public/stripe_webhook */
    private function a_stripe_webhook() {
        $payload = file_get_contents('php://input');
        $event = ShopPayments::stripeVerifyWebhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
        $type = $event['type'] ?? '';
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $s = ShopPayments::stripeGetSession($event['data']['object']['id']);
            if ($s['paid'] && $s['orderId']) {
                (new ShopOrders($this->db))->markPaid($s['orderId'], 'stripe', $s['ref'], $s['fee'], $s['amount']);
            }
        }
        return ShopBase::ok(['received' => true]);
    }

    // --- Account cliente --------------------------------------------------

    private function a_customer_register() {
        $in = $this->in;
        $in['language'] = $this->lang();
        return ShopBase::ok($this->customers()->register($in));
    }

    private function a_customer_login() {
        return ShopBase::ok($this->customers()->login($this->p('email'), $this->p('password')));
    }

    private function a_customer_logout() {
        $this->customers()->logout($this->p('customerToken'));
        return ShopBase::ok();
    }

    private function a_customer_me() {
        return ShopBase::ok($this->customers()->me($this->p('customerToken')));
    }

    private function a_customer_update() {
        return ShopBase::ok($this->customers()->updateProfile($this->p('customerToken'), $this->p('profile', [])));
    }

    private function a_customer_password() {
        $this->customers()->changePassword($this->p('customerToken'), $this->p('currentPassword'), $this->p('newPassword'));
        return ShopBase::ok();
    }

    private function a_customer_forgot() {
        $this->customers()->forgot($this->p('email'));
        return ShopBase::ok();
    }

    private function a_customer_reset() {
        return ShopBase::ok($this->customers()->reset((string)$this->p('token'), $this->p('password')));
    }

    private function a_customer_address_save() {
        return ShopBase::ok(['addresses' => $this->customers()->saveAddress($this->p('customerToken'), $this->p('address', []))]);
    }

    private function a_customer_address_delete() {
        return ShopBase::ok(['addresses' => $this->customers()->deleteAddress($this->p('customerToken'), $this->p('id'))]);
    }

    private function a_customer_orders() {
        return ShopBase::ok(['orders' => $this->customers()->orders($this->p('customerToken'))]);
    }

    private function a_customer_order() {
        return ShopBase::ok(['order' => $this->customers()->order($this->p('customerToken'), (string)$this->p('orderNumber'))]);
    }

    // --- Recensioni e avvisi ----------------------------------------------

    private function a_review_submit() {
        $customer = $this->customers()->requireCustomer($this->p('customerToken'));
        $in = $this->in;
        $in['language'] = $this->lang();
        return ShopBase::ok((new ShopEngagement($this->db))->submitReview($customer, $in));
    }

    private function a_stock_alert_subscribe() {
        (new ShopEngagement($this->db))->subscribeAlert($this->p('variantId'), $this->p('email'), $this->lang());
        return ShopBase::ok();
    }
}
