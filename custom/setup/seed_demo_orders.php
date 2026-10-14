<?php
/**
 * Ordini DEMO per vedere popolate dashboard e statistiche in sviluppo.
 * NON usare in produzione.
 *
 *   php custom/setup/seed_demo_orders.php [numero_ordini=140]
 *
 * Cancella ordini, clienti, recensioni e movimenti esistenti, poi rigenera
 * clienti, ordini online e vendite dirette degli ultimi 6 mesi con i
 * relativi scarichi di magazzino.
 */
if (PHP_SAPI !== 'cli') exit("Solo da riga di comando.\n");

$root = dirname(__DIR__, 2);
chdir($root . '/api');
require 'functions.php';
ckd_require_class('Database');
require $root . '/custom/shop/ShopBase.php';

$db = (new Database())->dbConnection();
$b = new ShopBase($db);
mt_srand(42);
$n = (int)($argv[1] ?? 140);

foreach (['shop_order', 'shop_order_item', 'customer', 'customer_address', 'customer_token', 'review', 'stock_alert'] as $t) {
    $db->exec("TRUNCATE `$t`");
}
$db->exec("DELETE FROM stock_movement WHERE type <> 'carico'");
$db->exec("UPDATE discount_code SET usedCount = 0");

$variants = $b->all("SELECT v.id, v.format, v.sku, v.price, p.name_it, p.id AS productId FROM product_variant v JOIN product p ON p.id = v.idProduct WHERE v.deleted = 0");
// ricarica abbondante: la demo non deve finire in negativo
foreach ($variants as $v) {
    $b->insert('stock_movement', ['idVariant' => $v['id'], 'type' => 'carico', 'quantity' => 150, 'notes' => 'Carico demo']);
}

$names = [['Giulia', 'Bianchi'], ['Marco', 'Rossi'], ['Elena', 'Conti'], ['Luca', 'Ferrari'], ['Sara', 'Romano'], ['Paolo', 'Galli'],
    ['Chiara', 'Costa'], ['Davide', 'Fontana'], ['Anna', 'Moretti'], ['Matteo', 'Ricci'], ['Hannah', 'Müller'], ['Sophie', 'Laurent'],
    ['Lukas', 'Schmidt'], ['Emma', 'de Vries'], ['Francesca', 'Greco'], ['Andrea', 'Bruno'], ['Laura', 'Gallo'], ['Stefano', 'Marini']];
$cities = [['IT', 'Treviso', '31100', 'TV'], ['IT', 'Milano', '20121', 'MI'], ['IT', 'Venezia', '30121', 'VE'], ['IT', 'Roma', '00184', 'RM'],
    ['IT', 'Padova', '35121', 'PD'], ['IT', 'Bologna', '40121', 'BO'], ['IT', 'Torino', '10121', 'TO'], ['DE', 'München', '80331', ''],
    ['FR', 'Paris', '75004', ''], ['AT', 'Wien', '1010', ''], ['NL', 'Amsterdam', '1012', '']];

// clienti registrati (metà dei nomi)
$customers = [];
foreach (array_slice($names, 0, 10) as $i => [$fn, $ln]) {
    $email = strtolower($fn . '.' . str_replace(' ', '', $ln)) . '@example.com';
    $id = $b->insert('customer', [
        'firstName' => $fn, 'lastName' => $ln, 'email' => $email, 'language' => 'it',
        'passwordHash' => password_hash('demo-password', PASSWORD_DEFAULT), 'marketingConsent' => $i % 2, 'disabled' => 0,
    ]);
    $db->exec("UPDATE customer SET date = DATE_SUB(NOW(), INTERVAL " . mt_rand(10, 200) . " DAY) WHERE id = $id");
    $customers[] = ['id' => $id, 'email' => $email, 'fn' => $fn, 'ln' => $ln];
}

$statuses = ['consegnato', 'consegnato', 'consegnato', 'spedito', 'in_preparazione', 'pagato', 'in_attesa_pagamento', 'annullato'];
for ($k = 0; $k < $n; $k++) {
    $daysAgo = (int)floor(pow(mt_rand(0, 1000) / 1000, 1.6) * 180);
    $paidAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days " . mt_rand(8, 20) . ':' . mt_rand(10, 59)));
    $direct = mt_rand(1, 100) <= 22;
    $status = $direct ? 'consegnato' : ($daysAgo > 7 ? (mt_rand(1, 100) <= 92 ? 'consegnato' : (mt_rand(0, 1) ? 'annullato' : 'rimborsato')) : $statuses[mt_rand(0, count($statuses) - 1)]);
    $useCustomer = !$direct && mt_rand(1, 100) <= 55;
    if ($useCustomer) {
        $c = $customers[mt_rand(0, count($customers) - 1)];
        [$fn, $ln, $email, $cid] = [$c['fn'], $c['ln'], $c['email'], $c['id']];
    } else {
        [$fn, $ln] = $names[mt_rand(0, count($names) - 1)];
        $email = $direct && mt_rand(0, 1) ? '' : strtolower($fn) . mt_rand(1, 99) . '@example.com';
        $cid = null;
    }
    $place = $cities[mt_rand(1, 100) <= 80 ? mt_rand(0, 6) : mt_rand(7, 10)];

    $lines = [];
    $count = mt_rand(1, min(2, count($variants)));
    foreach ((array)array_rand($variants, $count) as $vi) {
        $v = $variants[$vi];
        $q = mt_rand(1, 100) <= 75 ? 1 : mt_rand(2, 4);
        $lines[] = [$v, $q, round($v['price'] * $q, 2)];
    }
    $subtotal = round(array_sum(array_column($lines, 2)), 2);
    $discount = (!$direct && mt_rand(1, 100) <= 15 && $subtotal >= 30) ? round($subtotal * 0.10, 2) : 0;
    $eu = $place[0] !== 'IT';
    $shipping = $direct ? 0 : ((($subtotal - $discount) >= ($eu ? 150 : 80)) ? 0 : ($eu ? 19.90 : 8.90));
    $total = round($subtotal - $discount + $shipping, 2);
    $method = $direct ? ['contanti', 'pos', 'pos', 'bonifico'][mt_rand(0, 3)] : (mt_rand(1, 100) <= 70 ? 'stripe' : 'paypal');
    $fee = $method === 'stripe' ? round($total * 0.015 + 0.25, 2) : ($method === 'paypal' ? round($total * 0.0349 + 0.35, 2) : ($method === 'pos' ? round($total * 0.01, 2) : 0));
    $isPaid = in_array($status, ['pagato', 'in_preparazione', 'spedito', 'consegnato', 'rimborsato'], true);

    $id = $b->insert('shop_order', [
        'number' => 'TMP' . $k, 'channel' => $direct ? 'diretta' : 'online', 'status' => $status, 'paymentMethod' => $method,
        'paymentRef' => $isPaid ? strtoupper(substr(md5((string)$k), 0, 14)) : null, 'paidAt' => $isPaid ? $paidAt : null,
        'paymentFee' => $isPaid ? $fee : null, 'idCustomer' => $cid, 'email' => $email, 'firstName' => $fn, 'lastName' => $ln,
        'shipAddress' => $direct ? null : 'Via Esempio ' . mt_rand(1, 120), 'shipCity' => $direct ? null : $place[1],
        'shipZip' => $direct ? null : $place[2], 'shipProvince' => $direct ? null : $place[3], 'shipCountry' => $direct ? null : $place[0],
        'shippingZone' => $direct ? null : ($eu ? 'Unione Europea' : 'Italia'), 'subtotal' => $subtotal,
        'discountCode' => $discount ? 'BENVENUTO10' : null, 'discountAmount' => $discount, 'shippingCost' => $shipping, 'total' => $total,
        'language' => $eu ? 'en' : 'it', 'stockApplied' => 0, 'accessToken' => bin2hex(random_bytes(16)),
        'shippedAt' => in_array($status, ['spedito', 'consegnato'], true) && !$direct ? date('Y-m-d H:i:s', strtotime($paidAt . ' +' . mt_rand(18, 60) . ' hours')) : null,
        'trackingCarrier' => in_array($status, ['spedito', 'consegnato'], true) && !$direct ? 'BRT' : null,
        'trackingNumber' => in_array($status, ['spedito', 'consegnato'], true) && !$direct ? (string)mt_rand(100000000, 999999999) : null,
    ]);
    $db->exec("UPDATE shop_order SET number = 'CA-" . date('Y', strtotime($paidAt)) . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT) . "', date = '" . date('Y-m-d H:i:s', strtotime($paidAt . ' -3 minutes')) . "' WHERE id = $id");
    $number = $b->value("SELECT number FROM shop_order WHERE id = $id");
    foreach ($lines as [$v, $q, $tot]) {
        $b->insert('shop_order_item', ['idOrder' => $id, 'idVariant' => $v['id'], 'productName' => $v['name_it'], 'format' => $v['format'],
            'sku' => $v['sku'], 'unitPrice' => $v['price'], 'quantity' => $q, 'lineTotal' => $tot]);
        if (in_array($status, ['pagato', 'in_preparazione', 'spedito', 'consegnato'], true)) {
            $b->insert('stock_movement', ['idVariant' => $v['id'], 'type' => $direct ? 'vendita_diretta' : 'vendita_online', 'quantity' => -$q, 'idOrder' => $id, 'notes' => 'Ordine ' . $number]);
        }
    }
    if (in_array($status, ['pagato', 'in_preparazione', 'spedito', 'consegnato'], true)) $db->exec("UPDATE shop_order SET stockApplied = 1 WHERE id = $id");

    // qualche recensione dai clienti registrati
    if ($cid && $status === 'consegnato' && mt_rand(1, 100) <= 45) {
        $v = $lines[0][0];
        $dup = $b->value("SELECT id FROM review WHERE idCustomer = $cid AND idProduct = {$v['productId']}");
        if (!$dup) {
            $texts = ['Profumo netto di erba e mandorla, lo usiamo a crudo.', 'Arrivato in due giorni, bottiglia imballata con cura.',
                'Fruttato e leggermente piccante, equilibrato.', 'Regalato ai miei genitori. Lo riordineremo con il prossimo raccolto.'];
            $b->insert('review', ['idProduct' => $v['productId'], 'idCustomer' => $cid, 'idOrder' => $id, 'authorName' => $fn . ' ' . mb_substr($ln, 0, 1) . '.',
                'rating' => mt_rand(1, 100) <= 75 ? 5 : 4, 'title' => ['A crudo', 'Raccolto 2025', 'Equilibrato', 'Da riordinare'][mt_rand(0, 3)],
                'body' => $texts[mt_rand(0, 3)], 'status' => mt_rand(1, 100) <= 80 ? 'approvata' : 'in_attesa', 'language' => 'it']);
        }
    }
}

$db->exec("UPDATE discount_code SET usedCount = (SELECT COUNT(*) FROM shop_order WHERE discountCode = 'BENVENUTO10' AND paidAt IS NOT NULL) WHERE code = 'BENVENUTO10'");
echo "Creati $n ordini demo, " . count($customers) . " clienti, " . $b->value("SELECT COUNT(*) FROM review") . " recensioni.\n";
