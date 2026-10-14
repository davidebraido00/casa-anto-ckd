<?php
require_once __DIR__ . '/ShopBase.php';

/**
 * Calcolo del carrello lato server: prezzi, disponibilità, sconto e
 * spedizione. Il sito non è mai la fonte dei prezzi: manda solo
 * {variantId, quantity}.
 */
class ShopCart extends ShopBase {

    /**
     * @param array  $items   [{variantId, quantity}]
     * @param string $country ISO2 di spedizione (null = solo subtotale)
     * @param string $code    codice sconto
     * @return array quote con lines, subtotal, discount, shipping, total, issues
     */
    public function quote(array $items, $country, $code, $lang = 'it') {
        $qty = [];
        foreach ($items as $it) {
            $vid = (int)($it['variantId'] ?? 0);
            $q = (int)($it['quantity'] ?? 0);
            if ($vid > 0 && $q > 0) $qty[$vid] = ($qty[$vid] ?? 0) + min($q, 99);
        }

        $lines = [];
        $issues = [];
        if ($qty) {
            $in = implode(',', array_keys($qty));
            $rows = $this->all(
                "SELECT v.id, v.format, v.sku, v.price, v.fullName, v.active AS vActive, p.id AS productId, p.slug,
                        p.name_it, p.name_en, p.image, p.active AS pActive
                 FROM product_variant v JOIN product p ON p.id = v.idProduct
                 WHERE v.id IN ($in) AND v.deleted = 0 AND p.deleted = 0"
            );
            $byId = [];
            foreach ($rows as $r) $byId[(int)$r['id']] = $r;
            $stock = $this->stockMap(array_keys($qty));

            foreach ($qty as $vid => $q) {
                $r = $byId[$vid] ?? null;
                if (!$r || !(int)$r['vActive'] || !(int)$r['pActive']) {
                    $issues[] = ['variantId' => $vid, 'type' => 'unavailable'];
                    continue;
                }
                $available = max(0, $stock[$vid] ?? 0);
                if ($available <= 0) {
                    $issues[] = ['variantId' => $vid, 'type' => 'out_of_stock'];
                    continue;
                }
                if ($q > $available) {
                    $issues[] = ['variantId' => $vid, 'type' => 'quantity_reduced', 'available' => $available];
                    $q = $available;
                }
                $name = ($lang === 'en' && $r['name_en']) ? $r['name_en'] : $r['name_it'];
                $price = self::money($r['price']);
                $lines[] = [
                    'variantId' => $vid,
                    'productId' => (int)$r['productId'],
                    'slug' => $r['slug'],
                    'productName' => $name,
                    'format' => $r['format'],
                    'sku' => $r['sku'],
                    'image' => $this->fileUrl($r['image']),
                    'unitPrice' => $price,
                    'quantity' => $q,
                    'lineTotal' => self::money($price * $q),
                    'maxQty' => $available,
                ];
            }
        }

        $subtotal = self::money(array_sum(array_column($lines, 'lineTotal')));

        // sconto
        $discount = 0.0;
        $freeShipping = false;
        $discountInfo = null;
        if ($code !== null && trim($code) !== '') {
            $check = $this->checkDiscount($code, $subtotal);
            if ($check['valid']) {
                $d = $check['row'];
                if ($d['type'] === 'percentuale') $discount = self::money($subtotal * min(100, (float)$d['value']) / 100);
                if ($d['type'] === 'importo') $discount = self::money(min($subtotal, (float)$d['value']));
                if ($d['type'] === 'spedizione_gratuita') $freeShipping = true;
                $discountInfo = ['code' => $d['code'], 'type' => $d['type'], 'value' => (float)$d['value']];
            } else {
                $issues[] = [
                    'type' => 'discount_' . $check['reason'],
                    'code' => strtoupper(trim($code)),
                    'minOrder' => isset($check['row']) ? (float)$check['row']['minOrder'] : null,
                ];
            }
        }
        $afterDiscount = self::money($subtotal - $discount);

        // spedizione
        $shipping = null;
        $zone = null;
        if ($country) {
            $zone = $this->zoneFor($country);
            if (!$zone) {
                $issues[] = ['type' => 'country_not_served', 'country' => strtoupper($country)];
            } else {
                $threshold = $zone['freeThreshold'] !== null ? (float)$zone['freeThreshold'] : null;
                $free = $freeShipping || ($threshold !== null && $threshold > 0 && $afterDiscount >= $threshold);
                $shipping = $free || !$lines ? 0.0 : self::money($zone['cost']);
            }
        }

        $total = self::money($afterDiscount + ($shipping ?? 0));

        return [
            'lines' => $lines,
            'itemCount' => array_sum(array_column($lines, 'quantity')),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discountInfo' => $discountInfo,
            'shipping' => $shipping,
            'shippingZone' => $zone ? [
                'name' => ($lang === 'en' && !empty($zone['name_en'])) ? $zone['name_en'] : $zone['name'],
                'cost' => (float)$zone['cost'],
                'freeThreshold' => $zone['freeThreshold'] !== null ? (float)$zone['freeThreshold'] : null,
                'deliveryDays' => ($lang === 'en' && !empty($zone['deliveryDays_en'])) ? $zone['deliveryDays_en'] : $zone['deliveryDays'],
                'missingForFree' => ($zone['freeThreshold'] !== null && !$freeShipping && $afterDiscount < (float)$zone['freeThreshold'])
                    ? self::money((float)$zone['freeThreshold'] - $afterDiscount) : 0,
            ] : null,
            'total' => $total,
            'issues' => $issues,
        ];
    }

    public function zoneFor($country) {
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) return null;
        $zones = $this->all("SELECT * FROM shipping_zone WHERE deleted = 0 AND active = 1 ORDER BY sortOrder, id");
        foreach ($zones as $z) {
            $list = array_map('trim', explode(',', strtoupper($z['countries'])));
            if (in_array($country, $list, true)) return $z;
        }
        return null;
    }

    /** @return array {valid, reason, row} */
    public function checkDiscount($code, $subtotal) {
        $code = strtoupper(trim($code));
        $d = $this->one("SELECT * FROM discount_code WHERE UPPER(code) = :c AND deleted = 0 LIMIT 1", [':c' => $code]);
        if (!$d || !(int)$d['active']) return ['valid' => false, 'reason' => 'invalid'];
        $today = date('Y-m-d');
        if ($d['startsAt'] && $today < $d['startsAt']) return ['valid' => false, 'reason' => 'not_started'];
        if ($d['endsAt'] && $today > $d['endsAt']) return ['valid' => false, 'reason' => 'expired'];
        if ($d['maxUses'] !== null && (int)$d['maxUses'] > 0 && (int)$d['usedCount'] >= (int)$d['maxUses']) {
            return ['valid' => false, 'reason' => 'exhausted'];
        }
        if ($d['minOrder'] !== null && $subtotal < (float)$d['minOrder']) {
            return ['valid' => false, 'reason' => 'min_order', 'row' => $d];
        }
        return ['valid' => true, 'reason' => null, 'row' => $d];
    }
}
