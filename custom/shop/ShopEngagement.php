<?php
require_once __DIR__ . '/ShopBase.php';
require_once __DIR__ . '/ShopMailer.php';

/**
 * Recensioni (solo da chi ha acquistato) e avvisi "torna disponibile".
 */
class ShopEngagement extends ShopBase {

    public function submitReview($customer, array $in) {
        $productId = (int)($in['productId'] ?? 0);
        $rating = (int)($in['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) throw new ShopException('Valutazione non valida.', 'review.rating');
        $body = trim($in['body'] ?? '');
        if (mb_strlen($body) < 10) throw new ShopException('Scrivi almeno qualche parola.', 'review.body');

        $order = $this->one(
            "SELECT o.id FROM shop_order o
             JOIN shop_order_item i ON i.idOrder = o.id AND i.deleted = 0
             JOIN product_variant v ON v.id = i.idVariant
             WHERE o.idCustomer = :c AND v.idProduct = :p AND o.deleted = 0
               AND o.status IN ('pagato', 'in_preparazione', 'spedito', 'consegnato')
             ORDER BY o.date DESC LIMIT 1",
            [':c' => $customer['id'], ':p' => $productId]
        );
        if (!$order) throw new ShopException('Puoi recensire solo i prodotti che hai acquistato.', 'review.not_purchased');
        $dup = $this->one("SELECT id FROM review WHERE idCustomer = :c AND idProduct = :p AND deleted = 0", [':c' => $customer['id'], ':p' => $productId]);
        if ($dup) throw new ShopException('Hai già recensito questo prodotto.', 'review.duplicate');

        $auto = (int)($this->shopSettings()['reviewsAutoApprove'] ?? 0) === 1;
        $name = trim($in['authorName'] ?? '') ?: trim($customer['firstName'] . ' ' . mb_substr((string)$customer['lastName'], 0, 1) . '.');
        $this->insert('review', [
            'idProduct' => $productId,
            'idCustomer' => $customer['id'],
            'idOrder' => $order['id'],
            'authorName' => mb_substr($name, 0, 100),
            'rating' => $rating,
            'title' => mb_substr(trim($in['title'] ?? ''), 0, 200),
            'body' => mb_substr($body, 0, 4000),
            'status' => $auto ? 'approvata' : 'in_attesa',
            'language' => self::lang($in['language'] ?? 'it'),
        ]);
        return ['status' => $auto ? 'approvata' : 'in_attesa'];
    }

    public function subscribeAlert($variantId, $email, $lang) {
        $email = strtolower(trim((string)$email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ShopException('Email non valida.', 'alert.invalid_email');
        $v = $this->one("SELECT id FROM product_variant WHERE id = :v AND deleted = 0", [':v' => (int)$variantId]);
        if (!$v) throw new ShopException('Prodotto non trovato.', 'alert.not_found');
        $exists = $this->one("SELECT id FROM stock_alert WHERE idVariant = :v AND email = :e AND status = 'attivo' AND deleted = 0", [':v' => $v['id'], ':e' => $email]);
        if (!$exists) {
            $this->insert('stock_alert', ['idVariant' => $v['id'], 'email' => $email, 'language' => self::lang($lang), 'status' => 'attivo']);
        }
        return true;
    }

    /** Invia gli avvisi per le varianti tornate disponibili. @return int email inviate */
    public function processAlerts() {
        $alerts = $this->all(
            "SELECT a.*, v.idProduct, v.format, p.name_it, p.name_en, p.slug
             FROM stock_alert a
             JOIN product_variant v ON v.id = a.idVariant AND v.deleted = 0 AND v.active = 1
             JOIN product p ON p.id = v.idProduct AND p.deleted = 0 AND p.active = 1
             WHERE a.status = 'attivo' AND a.deleted = 0"
        );
        if (!$alerts) return 0;
        $stock = $this->stockMap(array_column($alerts, 'idVariant'));
        $mailer = new ShopMailer($this->db);
        $sent = 0;
        foreach ($alerts as $a) {
            if (($stock[(int)$a['idVariant']] ?? 0) <= 0) continue;
            $name = (($a['language'] === 'en' && $a['name_en']) ? $a['name_en'] : $a['name_it']) . ' ' . $a['format'];
            $mailer->backInStock($a['email'], $a['language'], $name, $a['slug']);
            $this->update('stock_alert', $a['id'], ['status' => 'notificato', 'notifiedAt' => date('Y-m-d H:i:s')]);
            $sent++;
        }
        return $sent;
    }
}
