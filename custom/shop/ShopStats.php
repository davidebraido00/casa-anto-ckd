<?php
require_once __DIR__ . '/ShopBase.php';

/**
 * Statistiche per il backoffice. Un ordine conta come incassato quando
 * status ∈ (pagato, in_preparazione, spedito, consegnato); la data di
 * riferimento è paidAt.
 */
class ShopStats extends ShopBase {

    const PAID = "('pagato','in_preparazione','spedito','consegnato')";

    private function range($from, $to) {
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from) ? $from : date('Y-m-01');
        $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to) ? $to : date('Y-m-d');
        if ($t < $f) [$f, $t] = [$t, $f];
        return [$f . ' 00:00:00', $t . ' 23:59:59', $f, $t];
    }

    private function revenue($from, $to) {
        $r = $this->one(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS gross, COALESCE(SUM(paymentFee), 0) AS fees,
                    COALESCE(SUM(CASE WHEN channel = 'online' THEN total END), 0) AS online,
                    COALESCE(SUM(CASE WHEN channel = 'diretta' THEN total END), 0) AS direct,
                    COALESCE(SUM(shippingCost), 0) AS shipping, COALESCE(SUM(discountAmount), 0) AS discounts
             FROM shop_order WHERE deleted = 0 AND status IN " . self::PAID . " AND paidAt BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
        );
        $gross = (float)$r['gross'];
        $orders = (int)$r['orders'];
        return [
            'orders' => $orders,
            'gross' => round($gross, 2),
            'fees' => round((float)$r['fees'], 2),
            'net' => round($gross - (float)$r['fees'], 2),
            'online' => round((float)$r['online'], 2),
            'direct' => round((float)$r['direct'], 2),
            'shipping' => round((float)$r['shipping'], 2),
            'discounts' => round((float)$r['discounts'], 2),
            'aov' => $orders ? round($gross / $orders, 2) : 0,
        ];
    }

    public function stats($fromDate, $toDate) {
        [$from, $to, $f, $t] = $this->range($fromDate, $toDate);
        $days = (int)round((strtotime($t) - strtotime($f)) / 86400) + 1;
        $prevTo = date('Y-m-d', strtotime($f . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));

        $current = $this->revenue($from, $to);
        $previous = $this->revenue($prevFrom . ' 00:00:00', $prevTo . ' 23:59:59');

        $items = $this->one(
            "SELECT COALESCE(SUM(i.quantity), 0) AS q FROM shop_order_item i JOIN shop_order o ON o.id = i.idOrder
             WHERE o.deleted = 0 AND i.deleted = 0 AND o.status IN " . self::PAID . " AND o.paidAt BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
        );
        $current['itemsSold'] = (int)$items['q'];

        $refunds = $this->one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount FROM shop_order
             WHERE deleted = 0 AND status = 'rimborsato' AND lastUpdate BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
        );
        $current['refunds'] = ['count' => (int)$refunds['n'], 'amount' => round((float)$refunds['amount'], 2)];

        // serie temporale: giorno, o mese oltre i 92 giorni
        $byMonth = $days > 92;
        $fmt = $byMonth ? '%Y-%m' : '%Y-%m-%d';
        $rows = $this->all(
            "SELECT DATE_FORMAT(paidAt, '$fmt') AS k,
                    COALESCE(SUM(CASE WHEN channel = 'online' THEN total END), 0) AS online,
                    COALESCE(SUM(CASE WHEN channel = 'diretta' THEN total END), 0) AS direct,
                    COUNT(*) AS orders
             FROM shop_order WHERE deleted = 0 AND status IN " . self::PAID . " AND paidAt BETWEEN :f AND :t
             GROUP BY k ORDER BY k",
            [':f' => $from, ':t' => $to]
        );
        $map = [];
        foreach ($rows as $r) $map[$r['k']] = $r;
        $series = [];
        $cursor = strtotime($byMonth ? date('Y-m-01', strtotime($f)) : $f);
        $end = strtotime($t);
        while ($cursor <= $end) {
            $k = date($byMonth ? 'Y-m' : 'Y-m-d', $cursor);
            $series[] = [
                'key' => $k,
                'online' => round((float)($map[$k]['online'] ?? 0), 2),
                'direct' => round((float)($map[$k]['direct'] ?? 0), 2),
                'orders' => (int)($map[$k]['orders'] ?? 0),
            ];
            $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
        }

        $byPayment = array_map(function ($r) {
            return [
                'method' => $r['paymentMethod'] ?: 'n/d',
                'orders' => (int)$r['n'],
                'gross' => round((float)$r['gross'], 2),
                'fees' => round((float)$r['fees'], 2),
                'net' => round((float)$r['gross'] - (float)$r['fees'], 2),
            ];
        }, $this->all(
            "SELECT paymentMethod, COUNT(*) AS n, SUM(total) AS gross, COALESCE(SUM(paymentFee), 0) AS fees
             FROM shop_order WHERE deleted = 0 AND status IN " . self::PAID . " AND paidAt BETWEEN :f AND :t
             GROUP BY paymentMethod ORDER BY gross DESC",
            [':f' => $from, ':t' => $to]
        ));

        $topProducts = array_map(function ($r) {
            return ['name' => $r['name'], 'quantity' => (int)$r['q'], 'revenue' => round((float)$r['rev'], 2)];
        }, $this->all(
            "SELECT CONCAT(i.productName, ' ', i.format) AS name, SUM(i.quantity) AS q, SUM(i.lineTotal) AS rev
             FROM shop_order_item i JOIN shop_order o ON o.id = i.idOrder
             WHERE o.deleted = 0 AND i.deleted = 0 AND o.status IN " . self::PAID . " AND o.paidAt BETWEEN :f AND :t
             GROUP BY i.idVariant, name ORDER BY rev DESC LIMIT 15",
            [':f' => $from, ':t' => $to]
        ));

        $byProduct = array_map(function ($r) {
            return ['name' => $r['name'], 'quantity' => (int)$r['q'], 'revenue' => round((float)$r['rev'], 2)];
        }, $this->all(
            "SELECT i.productName AS name, SUM(i.quantity) AS q, SUM(i.lineTotal) AS rev
             FROM shop_order_item i JOIN shop_order o ON o.id = i.idOrder
             WHERE o.deleted = 0 AND i.deleted = 0 AND o.status IN " . self::PAID . " AND o.paidAt BETWEEN :f AND :t
             GROUP BY i.productName ORDER BY rev DESC",
            [':f' => $from, ':t' => $to]
        ));

        // clienti: acquirenti del periodo, nuovi (primo acquisto nel periodo) o di ritorno
        $buyers = $this->all(
            "SELECT LOWER(o.email) AS email, COUNT(*) AS n, SUM(o.total) AS spent,
                    MAX(CONCAT(o.firstName, ' ', o.lastName)) AS name,
                    (SELECT MIN(p.paidAt) FROM shop_order p WHERE p.deleted = 0 AND p.status IN " . self::PAID . " AND LOWER(p.email) = LOWER(o.email)) AS firstPaid
             FROM shop_order o
             WHERE o.deleted = 0 AND o.status IN " . self::PAID . " AND o.paidAt BETWEEN :f AND :t AND o.email IS NOT NULL AND o.email <> ''
             GROUP BY LOWER(o.email)",
            [':f' => $from, ':t' => $to]
        );
        $new = 0; $returning = 0;
        foreach ($buyers as $b) {
            if ($b['firstPaid'] >= $from) $new++; else $returning++;
        }
        usort($buyers, function ($a, $b) { return $b['spent'] <=> $a['spent']; });
        $topCustomers = array_map(function ($b) {
            return ['name' => trim($b['name']), 'email' => $b['email'], 'orders' => (int)$b['n'], 'spent' => round((float)$b['spent'], 2)];
        }, array_slice($buyers, 0, 10));

        $registered = (int)$this->value("SELECT COUNT(*) FROM customer WHERE deleted = 0 AND date BETWEEN :f AND :t", [':f' => $from, ':t' => $to]);

        $countries = array_map(function ($r) {
            return ['country' => $r['c'] ?: 'n/d', 'orders' => (int)$r['n'], 'revenue' => round((float)$r['rev'], 2)];
        }, $this->all(
            "SELECT shipCountry AS c, COUNT(*) AS n, SUM(total) AS rev FROM shop_order
             WHERE deleted = 0 AND channel = 'online' AND status IN " . self::PAID . " AND paidAt BETWEEN :f AND :t
             GROUP BY shipCountry ORDER BY n DESC",
            [':f' => $from, ':t' => $to]
        ));

        $statuses = array_map(function ($r) {
            return ['status' => $r['status'], 'count' => (int)$r['n']];
        }, $this->all(
            "SELECT status, COUNT(*) AS n FROM shop_order WHERE deleted = 0 AND channel = 'online' AND date BETWEEN :f AND :t GROUP BY status",
            [':f' => $from, ':t' => $to]
        ));

        $fulfil = $this->one(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, paidAt, shippedAt)) AS h, COUNT(*) AS n FROM shop_order
             WHERE deleted = 0 AND channel = 'online' AND shippedAt IS NOT NULL AND paidAt IS NOT NULL AND paidAt BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
        );
        $created = (int)$this->value("SELECT COUNT(*) FROM shop_order WHERE deleted = 0 AND channel = 'online' AND date BETWEEN :f AND :t", [':f' => $from, ':t' => $to]);
        $paidOnline = (int)$this->value("SELECT COUNT(*) FROM shop_order WHERE deleted = 0 AND channel = 'online' AND status IN " . self::PAID . " AND date BETWEEN :f AND :t", [':f' => $from, ':t' => $to]);

        return [
            'range' => ['from' => $f, 'to' => $t, 'days' => $days, 'granularity' => $byMonth ? 'month' : 'day',
                        'previous' => ['from' => $prevFrom, 'to' => $prevTo]],
            'kpis' => $current,
            'previous' => $previous,
            'series' => $series,
            'byPayment' => $byPayment,
            'topProducts' => $topProducts,
            'byProduct' => $byProduct,
            'customers' => [
                'buyers' => count($buyers), 'new' => $new, 'returning' => $returning,
                'registered' => $registered, 'top' => $topCustomers, 'countries' => $countries,
            ],
            'orders' => [
                'statuses' => $statuses,
                'created' => $created,
                'paidRate' => $created ? round($paidOnline / $created * 100, 1) : null,
                'avgHoursToShip' => $fulfil['h'] !== null ? round((float)$fulfil['h'], 1) : null,
            ],
            'stock' => $this->stockSummary(),
        ];
    }

    public function stockSummary() {
        $rows = $this->all("SELECT id, fullName, sku, stock, lowStockThreshold, stockStatus, stockValue FROM gyv_stock WHERE active = 1 ORDER BY stock ASC");
        return [
            'value' => round(array_sum(array_column($rows, 'stockValue')), 2),
            'units' => (int)array_sum(array_column($rows, 'stock')),
            'low' => array_values(array_map(function ($r) {
                return ['id' => (int)$r['id'], 'name' => $r['fullName'], 'sku' => $r['sku'], 'stock' => (int)$r['stock'],
                        'threshold' => (int)$r['lowStockThreshold'], 'status' => $r['stockStatus']];
            }, array_filter($rows, function ($r) { return $r['stockStatus'] !== 'ok'; }))),
        ];
    }

    public function dashboard() {
        $today = date('Y-m-d');
        $todayRev = $this->revenue($today . ' 00:00:00', $today . ' 23:59:59');
        $month = $this->revenue(date('Y-m-01') . ' 00:00:00', $today . ' 23:59:59');
        $lastMonthFrom = date('Y-m-01', strtotime('first day of last month'));
        $lastMonthTo = date('Y-m-d', strtotime($lastMonthFrom . ' +' . (date('j') - 1) . ' days'));
        $lastMonth = $this->revenue($lastMonthFrom . ' 00:00:00', min($lastMonthTo, date('Y-m-t', strtotime($lastMonthFrom))) . ' 23:59:59');
        $year = $this->revenue(date('Y-01-01') . ' 00:00:00', $today . ' 23:59:59');

        $toShip = $this->all(
            "SELECT id, number, date, paidAt, status, firstName, lastName, shipCountry, total FROM shop_order
             WHERE deleted = 0 AND channel = 'online' AND status IN ('pagato', 'in_preparazione') ORDER BY paidAt ASC LIMIT 50"
        );
        $recent = $this->all(
            "SELECT id, number, date, status, channel, firstName, lastName, total, paymentMethod FROM shop_order
             WHERE deleted = 0 AND status <> 'in_attesa_pagamento' ORDER BY COALESCE(paidAt, date) DESC LIMIT 8"
        );
        $pendingReviews = (int)$this->value("SELECT COUNT(*) FROM review WHERE deleted = 0 AND status = 'in_attesa'");
        $activeAlerts = (int)$this->value("SELECT COUNT(*) FROM stock_alert WHERE deleted = 0 AND status = 'attivo'");
        $abandoned = (int)$this->value(
            "SELECT COUNT(*) FROM shop_order WHERE deleted = 0 AND status = 'in_attesa_pagamento' AND date > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $last30 = [];
        $rows = $this->all(
            "SELECT DATE(paidAt) AS d, SUM(total) AS t FROM shop_order WHERE deleted = 0 AND status IN " . self::PAID . "
             AND paidAt >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY d"
        );
        $m = [];
        foreach ($rows as $r) $m[$r['d']] = (float)$r['t'];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $last30[] = ['key' => $d, 'total' => round($m[$d] ?? 0, 2)];
        }

        return [
            'today' => $todayRev,
            'month' => $month,
            'lastMonthSamePeriod' => $lastMonth,
            'year' => $year,
            'toShip' => $toShip,
            'recent' => $recent,
            'pendingReviews' => $pendingReviews,
            'activeAlerts' => $activeAlerts,
            'abandonedCheckouts7d' => $abandoned,
            'last30' => $last30,
            'stock' => $this->stockSummary(),
        ];
    }
}
