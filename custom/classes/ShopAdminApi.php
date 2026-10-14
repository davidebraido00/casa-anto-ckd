<?php
require_once __DIR__ . '/../shop/ShopBase.php';
require_once __DIR__ . '/../shop/ShopOrders.php';
require_once __DIR__ . '/../shop/ShopStats.php';
require_once __DIR__ . '/../shop/ShopEngagement.php';

/**
 * API del backoffice (/custom/{action}), solo per utenti di livello 0.
 */
class ShopAdminApi {
    protected $db;
    protected $user_info;
    protected $in;

    const ACTIONS = [
        'admin_dashboard', 'admin_stats', 'order_set_status', 'direct_sale_create',
        'variants_refresh_names', 'stock_alerts_process', 'stock_overview',
    ];

    function __construct($db, $user_info, $parameters) {
        $this->db = $db;
        $this->user_info = json_decode($user_info, true);
        $this->in = ShopBase::toArray($parameters);
    }

    public static function handles($action) {
        return in_array($action, self::ACTIONS, true);
    }

    public function handle($action) {
        if ((int)($this->user_info['level'] ?? 999) !== 0) {
            return ShopBase::fail('Permesso negato.', 'api.custom.forbidden', 403);
        }
        try {
            $method = 'a_' . $action;
            return $this->$method();
        } catch (ShopException $e) {
            return ShopBase::fail($e->getMessage(), $e->errorCode);
        } catch (Throwable $e) {
            error_log('[shop-admin] ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return ShopBase::fail('Errore: ' . $e->getMessage(), 'shop.internal', 500);
        }
    }

    private function p($key, $default = null) {
        return $this->in[$key] ?? $default;
    }

    private function a_admin_dashboard() {
        return ShopBase::ok(['data' => (new ShopStats($this->db))->dashboard()]);
    }

    private function a_admin_stats() {
        return ShopBase::ok(['data' => (new ShopStats($this->db))->stats($this->p('from'), $this->p('to'))]);
    }

    private function a_stock_overview() {
        return ShopBase::ok(['data' => (new ShopStats($this->db))->stockSummary()]);
    }

    private function a_order_set_status() {
        $res = (new ShopOrders($this->db))->setStatus((int)$this->p('id'), (string)$this->p('status'), [
            'trackingCarrier' => $this->p('trackingCarrier'),
            'trackingNumber' => $this->p('trackingNumber'),
            'trackingUrl' => $this->p('trackingUrl'),
            'restock' => $this->p('restock', true) ? true : false,
            'notify' => $this->p('notify', true) ? true : false,
        ]);
        // un annullo/rimborso può rimettere disponibile un prodotto
        $sent = (new ShopEngagement($this->db))->processAlerts();
        return ShopBase::ok(['order' => $res['order'], 'mail' => $res['mail'], 'alertsSent' => $sent], 'Stato aggiornato.');
    }

    private function a_direct_sale_create() {
        $order = (new ShopOrders($this->db))->createDirectSale($this->in, $this->user_info['id'] ?? null);
        return ShopBase::ok(['order' => $order], 'Vendita registrata: ' . $order['number']);
    }

    /** Ricalcola product_variant.fullName ("Prodotto formato") */
    private function a_variants_refresh_names() {
        $n = (new ShopBase($this->db))->exec(
            "UPDATE product_variant v JOIN product p ON p.id = v.idProduct
             SET v.fullName = TRIM(CONCAT(p.name_it, ' ', v.format))
             WHERE v.fullName IS NULL OR v.fullName <> TRIM(CONCAT(p.name_it, ' ', v.format))"
        );
        return ShopBase::ok(['updated' => $n]);
    }

    private function a_stock_alerts_process() {
        $sent = (new ShopEngagement($this->db))->processAlerts();
        return ShopBase::ok(['sent' => $sent], $sent ? "Inviati {$sent} avvisi." : 'Nessun avviso da inviare.');
    }
}
