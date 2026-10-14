<?php
require_once __DIR__ . '/ShopBase.php';
require_once __DIR__ . '/ShopCart.php';
require_once __DIR__ . '/ShopMailer.php';

/**
 * Ciclo di vita degli ordini: creazione dal checkout, conferma pagamento
 * (idempotente), cambi di stato, vendite dirette in azienda.
 *
 * Il magazzino viene scaricato solo quando l'ordine risulta pagato
 * (stockApplied evita il doppio scarico) e ricaricato su annullo/rimborso.
 */
class ShopOrders extends ShopBase {

    const PAID_STATUSES = ['pagato', 'in_preparazione', 'spedito', 'consegnato'];

    private function nextNumber($id) {
        $prefix = $this->shopSettings()['orderPrefix'] ?? 'CA';
        return strtoupper($prefix ?: 'CA') . '-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Crea un ordine "in attesa di pagamento" dal checkout del sito.
     *
     * @param array $in {items, customer:{email,firstName,lastName,phone}, shipping:{address,city,zip,province,country},
     *                   discountCode, paymentMethod, language, notes}
     * @param array|null $customer cliente loggato
     */
    public function createFromCheckout(array $in, $customer = null) {
        $lang = self::lang($in['language'] ?? 'it');
        $method = $in['paymentMethod'] ?? '';
        if (!in_array($method, ['stripe', 'paypal'], true)) throw new ShopException('Metodo di pagamento non valido.', 'checkout.payment_method');

        $c = $in['customer'] ?? [];
        $s = $in['shipping'] ?? [];
        $email = strtolower(trim($c['email'] ?? ($customer['email'] ?? '')));
        $required = [
            'email' => $email, 'firstName' => $c['firstName'] ?? '', 'lastName' => $c['lastName'] ?? '',
            'address' => $s['address'] ?? '', 'city' => $s['city'] ?? '', 'zip' => $s['zip'] ?? '', 'country' => $s['country'] ?? '',
        ];
        foreach ($required as $k => $v) {
            if (trim((string)$v) === '') throw new ShopException("Campo obbligatorio mancante: {$k}", 'checkout.missing_' . $k);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ShopException('Email non valida.', 'checkout.invalid_email');

        $cart = new ShopCart($this->db);
        $quote = $cart->quote($in['items'] ?? [], $s['country'], $in['discountCode'] ?? null, $lang);
        $blocking = array_filter($quote['issues'], function ($i) {
            return in_array($i['type'], ['unavailable', 'out_of_stock', 'quantity_reduced', 'country_not_served'], true)
                || strpos($i['type'], 'discount_') === 0;
        });
        if ($blocking) {
            $e = new ShopException('Il carrello è cambiato: controlla prima di procedere.', 'checkout.cart_changed');
            $e->quote = $quote;
            throw $e;
        }
        if (!$quote['lines']) throw new ShopException('Il carrello è vuoto.', 'checkout.empty');

        $this->db->beginTransaction();
        try {
            $orderId = $this->insert('shop_order', [
                'number' => 'TMP-' . self::randomToken(6),
                'channel' => 'online',
                'status' => 'in_attesa_pagamento',
                'paymentMethod' => $method,
                'idCustomer' => $customer ? (int)$customer['id'] : null,
                'email' => $email,
                'firstName' => trim($c['firstName']),
                'lastName' => trim($c['lastName']),
                'phone' => trim($c['phone'] ?? ''),
                'shipAddress' => trim($s['address']),
                'shipCity' => trim($s['city']),
                'shipZip' => trim($s['zip']),
                'shipProvince' => trim($s['province'] ?? ''),
                'shipCountry' => strtoupper(trim($s['country'])),
                'shippingZone' => $quote['shippingZone']['name'] ?? null,
                'subtotal' => $quote['subtotal'],
                'discountCode' => $quote['discountInfo']['code'] ?? null,
                'discountAmount' => $quote['discount'],
                'shippingCost' => $quote['shipping'] ?? 0,
                'total' => $quote['total'],
                'language' => $lang,
                'customerNotes' => mb_substr(trim($in['notes'] ?? ''), 0, 2000),
                'stockApplied' => 0,
                'accessToken' => self::randomToken(16),
            ]);
            $number = $this->nextNumber($orderId);
            $this->update('shop_order', $orderId, ['number' => $number]);
            foreach ($quote['lines'] as $l) {
                $this->insert('shop_order_item', [
                    'idOrder' => $orderId,
                    'idVariant' => $l['variantId'],
                    'productName' => $l['productName'],
                    'format' => $l['format'],
                    'sku' => $l['sku'],
                    'unitPrice' => $l['unitPrice'],
                    'quantity' => $l['quantity'],
                    'lineTotal' => $l['lineTotal'],
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->get($orderId);
    }

    public function get($id) {
        return $this->one("SELECT * FROM shop_order WHERE id = :id AND deleted = 0", [':id' => $id]);
    }

    public function getByNumber($number) {
        return $this->one("SELECT * FROM shop_order WHERE number = :n AND deleted = 0", [':n' => $number]);
    }

    public function items($orderId) {
        return $this->all("SELECT * FROM shop_order_item WHERE idOrder = :id AND deleted = 0 ORDER BY id", [':id' => $orderId]);
    }

    /**
     * Segna l'ordine come pagato. Idempotente: chiamabile sia dal webhook che
     * dal ritorno sul sito. @return array ordine aggiornato
     */
    public function markPaid($orderId, $method, $ref, $fee = null, $amount = null) {
        $this->db->beginTransaction();
        try {
            $order = $this->one("SELECT * FROM shop_order WHERE id = :id AND deleted = 0 FOR UPDATE", [':id' => $orderId]);
            if (!$order) throw new ShopException('Ordine non trovato.', 'order.not_found');
            if (in_array($order['status'], self::PAID_STATUSES, true) || $order['status'] === 'rimborsato') {
                $this->db->commit();
                return ['order' => $order, 'alreadyPaid' => true];
            }
            $notes = $order['internalNotes'];
            if ($amount !== null && abs((float)$amount - (float)$order['total']) > 0.01) {
                $notes = trim($notes . "\n⚠ Importo incassato " . number_format($amount, 2, ',', '.') . " diverso dal totale ordine.");
            }
            if ($order['status'] === 'annullato') {
                $notes = trim($notes . "\n⚠ Pagamento ricevuto su un ordine annullato: verificare.");
            }
            $this->update('shop_order', $orderId, [
                'status' => 'pagato',
                'paymentMethod' => $method,
                'paymentRef' => $ref,
                'paymentFee' => $fee,
                'paidAt' => date('Y-m-d H:i:s'),
                'internalNotes' => $notes,
            ]);
            $this->applyStock($orderId, 'vendita_online');
            if ($order['discountCode']) {
                $this->exec("UPDATE discount_code SET usedCount = COALESCE(usedCount, 0) + 1 WHERE UPPER(code) = UPPER(:c) AND deleted = 0", [':c' => $order['discountCode']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $order = $this->get($orderId);
        $items = $this->items($orderId);
        $mailer = new ShopMailer($this->db);
        $mailer->orderConfirmation($order, $items);
        $mailer->newOrderNotification($order, $items);
        return ['order' => $order, 'alreadyPaid' => false];
    }

    /** Scarica il magazzino per le righe dell'ordine (una sola volta) */
    private function applyStock($orderId, $type) {
        $order = $this->get($orderId);
        if ((int)$order['stockApplied'] === 1) return;
        $oversold = [];
        foreach ($this->items($orderId) as $it) {
            if (!$it['idVariant']) continue;
            $available = $this->stockOf($it['idVariant']);
            if ($available < (int)$it['quantity']) $oversold[] = $it['productName'] . ' ' . $it['format'];
            $this->insert('stock_movement', [
                'idVariant' => $it['idVariant'],
                'type' => $type,
                'quantity' => -abs((int)$it['quantity']),
                'idOrder' => $orderId,
                'notes' => 'Ordine ' . $order['number'],
            ]);
        }
        $data = ['stockApplied' => 1];
        if ($oversold) $data['internalNotes'] = trim($order['internalNotes'] . "\n⚠ Giacenza insufficiente al pagamento: " . implode(', ', $oversold));
        $this->update('shop_order', $orderId, $data);
    }

    /** Ricarica il magazzino (annullo/rimborso di un ordine già scaricato) */
    private function restock($orderId) {
        $order = $this->get($orderId);
        if ((int)$order['stockApplied'] !== 1) return;
        foreach ($this->items($orderId) as $it) {
            if (!$it['idVariant']) continue;
            $this->insert('stock_movement', [
                'idVariant' => $it['idVariant'],
                'type' => 'annullo',
                'quantity' => abs((int)$it['quantity']),
                'idOrder' => $orderId,
                'notes' => 'Annullo/rimborso ordine ' . $order['number'],
            ]);
        }
        $this->update('shop_order', $orderId, ['stockApplied' => 0]);
    }

    /**
     * Cambio di stato dal backoffice.
     * @param array $opts {trackingCarrier, trackingNumber, trackingUrl, restock(bool), notify(bool)}
     */
    public function setStatus($orderId, $status, array $opts = []) {
        $allowed = ['in_attesa_pagamento', 'pagato', 'in_preparazione', 'spedito', 'consegnato', 'annullato', 'rimborsato'];
        if (!in_array($status, $allowed, true)) throw new ShopException('Stato non valido.', 'order.status');
        $order = $this->get($orderId);
        if (!$order) throw new ShopException('Ordine non trovato.', 'order.not_found');

        $data = ['status' => $status];
        foreach (['trackingCarrier', 'trackingNumber', 'trackingUrl'] as $k) {
            if (isset($opts[$k])) $data[$k] = trim((string)$opts[$k]);
        }
        if ($status === 'spedito' && !$order['shippedAt']) $data['shippedAt'] = date('Y-m-d H:i:s');
        if (in_array($status, self::PAID_STATUSES, true) && !$order['paidAt']) $data['paidAt'] = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->update('shop_order', $orderId, $data);
            if (in_array($status, self::PAID_STATUSES, true)) {
                $this->applyStock($orderId, $order['channel'] === 'diretta' ? 'vendita_diretta' : 'vendita_online');
            }
            if (in_array($status, ['annullato', 'rimborsato'], true) && ($opts['restock'] ?? true)) {
                $this->restock($orderId);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $order = $this->get($orderId);
        $mail = null;
        if ($status === 'spedito' && ($opts['notify'] ?? true) && $order['channel'] === 'online') {
            $mail = (new ShopMailer($this->db))->orderShipped($order);
        }
        return ['order' => $order, 'mail' => $mail];
    }

    /**
     * Vendita diretta in azienda: ordine già pagato e consegnato,
     * scarica subito il magazzino.
     *
     * @param array $in {items:[{variantId, quantity, unitPrice?}], paymentMethod, discountAmount,
     *                   firstName, lastName, email, phone, notes, idCustomer}
     */
    public function createDirectSale(array $in, $userId) {
        $method = $in['paymentMethod'] ?? 'contanti';
        if (!in_array($method, ['contanti', 'pos', 'bonifico', 'paypal', 'stripe'], true)) $method = 'contanti';
        $lines = [];
        foreach ($in['items'] ?? [] as $it) {
            $vid = (int)($it['variantId'] ?? 0);
            $q = (int)($it['quantity'] ?? 0);
            if ($vid <= 0 || $q <= 0) continue;
            $v = $this->one(
                "SELECT v.*, p.name_it FROM product_variant v JOIN product p ON p.id = v.idProduct WHERE v.id = :id AND v.deleted = 0",
                [':id' => $vid]
            );
            if (!$v) throw new ShopException("Variante {$vid} non trovata.", 'direct.variant');
            $price = isset($it['unitPrice']) && $it['unitPrice'] !== '' ? self::money($it['unitPrice']) : self::money($v['price']);
            $lines[] = ['variant' => $v, 'quantity' => $q, 'unitPrice' => $price, 'lineTotal' => self::money($price * $q)];
        }
        if (!$lines) throw new ShopException('Aggiungi almeno un prodotto.', 'direct.empty');

        $subtotal = self::money(array_sum(array_column($lines, 'lineTotal')));
        $discount = min($subtotal, max(0, self::money($in['discountAmount'] ?? 0)));
        $total = self::money($subtotal - $discount);

        $this->db->beginTransaction();
        try {
            $orderId = $this->insert('shop_order', [
                'number' => 'TMP-' . self::randomToken(6),
                'channel' => 'diretta',
                'status' => 'consegnato',
                'paymentMethod' => $method,
                'paidAt' => !empty($in['date']) ? $in['date'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s'),
                'idCustomer' => !empty($in['idCustomer']) ? (int)$in['idCustomer'] : null,
                'email' => trim($in['email'] ?? ''),
                'firstName' => trim($in['firstName'] ?? ''),
                'lastName' => trim($in['lastName'] ?? ''),
                'phone' => trim($in['phone'] ?? ''),
                'subtotal' => $subtotal,
                'discountAmount' => $discount,
                'shippingCost' => 0,
                'total' => $total,
                'language' => 'it',
                'internalNotes' => trim($in['notes'] ?? ''),
                'stockApplied' => 0,
                'accessToken' => self::randomToken(16),
            ]);
            $this->update('shop_order', $orderId, ['number' => $this->nextNumber($orderId)]);
            foreach ($lines as $l) {
                $this->insert('shop_order_item', [
                    'idOrder' => $orderId,
                    'idVariant' => $l['variant']['id'],
                    'productName' => $l['variant']['name_it'],
                    'format' => $l['variant']['format'],
                    'sku' => $l['variant']['sku'],
                    'unitPrice' => $l['unitPrice'],
                    'quantity' => $l['quantity'],
                    'lineTotal' => $l['lineTotal'],
                ]);
            }
            $this->applyStock($orderId, 'vendita_diretta');
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->get($orderId);
    }

    /** Vista ordine per il cliente (senza campi interni) */
    public function publicView($order) {
        $items = array_map(function ($it) {
            return [
                'variantId' => (int)$it['idVariant'],
                'productName' => $it['productName'],
                'format' => $it['format'],
                'unitPrice' => (float)$it['unitPrice'],
                'quantity' => (int)$it['quantity'],
                'lineTotal' => (float)$it['lineTotal'],
            ];
        }, $this->items($order['id']));
        return [
            'number' => $order['number'],
            'date' => $order['date'],
            'status' => $order['status'],
            'paymentMethod' => $order['paymentMethod'],
            'paidAt' => $order['paidAt'],
            'email' => $order['email'],
            'firstName' => $order['firstName'],
            'lastName' => $order['lastName'],
            'shipping' => [
                'address' => $order['shipAddress'], 'city' => $order['shipCity'], 'zip' => $order['shipZip'],
                'province' => $order['shipProvince'], 'country' => $order['shipCountry'],
            ],
            'subtotal' => (float)$order['subtotal'],
            'discountCode' => $order['discountCode'],
            'discountAmount' => (float)$order['discountAmount'],
            'shippingCost' => (float)$order['shippingCost'],
            'total' => (float)$order['total'],
            'tracking' => $order['trackingNumber'] ? [
                'carrier' => $order['trackingCarrier'], 'number' => $order['trackingNumber'], 'url' => $order['trackingUrl'],
            ] : null,
            'shippedAt' => $order['shippedAt'],
            'items' => $items,
        ];
    }
}
