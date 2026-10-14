<?php
require_once __DIR__ . '/ShopBase.php';

/**
 * Catalogo pubblico: prodotti attivi con varianti, disponibilità e recensioni.
 * I testi vengono restituiti già nella lingua richiesta (fallback italiano).
 */
class ShopCatalog extends ShopBase {

    public function products($lang, $onlyFeatured = false) {
        $where = "p.deleted = 0 AND p.active = 1";
        if ($onlyFeatured) $where .= " AND p.featured = 1";
        $rows = $this->all("SELECT p.* FROM product p WHERE $where ORDER BY p.sortOrder, p.id");
        return array_map(function ($p) use ($lang) { return $this->format($p, $lang, false); }, $rows);
    }

    public function product($slug, $lang) {
        $p = $this->one("SELECT * FROM product WHERE slug = :s AND deleted = 0 AND active = 1", [':s' => $slug]);
        if (!$p) return null;
        return $this->format($p, $lang, true);
    }

    public function slugs() {
        return array_column($this->all("SELECT slug, lastUpdate FROM product WHERE deleted = 0 AND active = 1 ORDER BY sortOrder"), null);
    }

    private function t($row, $field, $lang) {
        $v = $row[$field . '_' . $lang] ?? null;
        if ($v === null || trim($v) === '') $v = $row[$field . '_it'] ?? null;
        return $v;
    }

    public function variants($productId) {
        $rows = $this->all(
            "SELECT id, format, sku, price, compareAtPrice, weightGrams, lowStockThreshold, fullName
             FROM product_variant WHERE idProduct = :p AND deleted = 0 AND active = 1
             ORDER BY sortOrder, price",
            [':p' => $productId]
        );
        $stock = $this->stockMap(array_column($rows, 'id'));
        return array_map(function ($v) use ($stock) {
            $s = $stock[(int)$v['id']] ?? 0;
            return [
                'id' => (int)$v['id'],
                'format' => $v['format'],
                'sku' => $v['sku'],
                'price' => (float)$v['price'],
                'compareAtPrice' => $v['compareAtPrice'] !== null ? (float)$v['compareAtPrice'] : null,
                'available' => $s > 0,
                // quantità esatta solo se bassa, per il messaggio "ultimi pezzi"
                'lowStock' => $s > 0 && $s <= (int)$v['lowStockThreshold'] ? $s : null,
                'maxQty' => max(0, $s),
            ];
        }, $rows);
    }

    public function ratingSummary($productId) {
        $r = $this->one("SELECT COUNT(*) AS n, AVG(rating) AS avg FROM review WHERE idProduct = :p AND status = 'approvata' AND deleted = 0", [':p' => $productId]);
        return ['count' => (int)$r['n'], 'average' => $r['n'] ? round((float)$r['avg'], 1) : null];
    }

    public function reviews($productId, $limit = 20) {
        $rows = $this->all(
            "SELECT id, date, authorName, rating, title, body, language FROM review
             WHERE idProduct = :p AND status = 'approvata' AND deleted = 0 ORDER BY date DESC LIMIT " . (int)$limit,
            [':p' => $productId]
        );
        return array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'date' => substr($r['date'], 0, 10),
                'authorName' => $r['authorName'],
                'rating' => (int)$r['rating'],
                'title' => $r['title'],
                'body' => $r['body'],
                'language' => $r['language'],
            ];
        }, $rows);
    }

    private function format($p, $lang, $full) {
        $variants = $this->variants($p['id']);
        $prices = array_column($variants, 'price');
        $images = [];
        foreach (['image', 'image2', 'image3', 'image4'] as $f) {
            $url = $this->fileUrl($p[$f] ?? null);
            if ($url) $images[] = $url;
        }
        $out = [
            'id' => (int)$p['id'],
            'slug' => $p['slug'],
            'name' => $this->t($p, 'name', $lang),
            'subtitle' => $this->t($p, 'subtitle', $lang),
            'intensity' => $p['intensity'],
            'featured' => (int)$p['featured'] === 1,
            'images' => $images,
            'priceFrom' => $prices ? min($prices) : null,
            'available' => (bool)array_filter($variants, function ($v) { return $v['available']; }),
            'variants' => $variants,
            'rating' => $this->ratingSummary($p['id']),
        ];
        if ($full) {
            $out += [
                'description' => $this->t($p, 'description', $lang),
                'tastingNotes' => $this->t($p, 'tastingNotes', $lang),
                'pairings' => $this->t($p, 'pairings', $lang),
                'cultivar' => $p['cultivar'],
                'acidity' => $p['acidity'],
                'harvest' => $this->t($p, 'harvest', $lang) ?? $p['harvest'],
                'reviews' => $this->reviews($p['id']),
                'updatedAt' => $p['lastUpdate'],
            ];
        }
        return $out;
    }

    /** Info del negozio per footer, contatti e pagina spedizioni */
    public function shopInfo($lang = 'it') {
        $s = $this->shopSettings();
        $zones = $this->all("SELECT name, name_en, countries, cost, freeThreshold, deliveryDays, deliveryDays_en FROM shipping_zone WHERE deleted = 0 AND active = 1 ORDER BY sortOrder, id");
        return [
            'shopName' => $s['shopName'] ?? 'Casa Anto',
            'contactEmail' => $s['contactEmail'] ?? null,
            'contactPhone' => $s['contactPhone'] ?? null,
            'address' => $s['address'] ?? null,
            'vatNumber' => $s['vatNumber'] ?? null,
            'shippingZones' => array_map(function ($z) use ($lang) {
                $en = $lang === 'en';
                return [
                    'name' => ($en && $z['name_en']) ? $z['name_en'] : $z['name'],
                    'countries' => array_values(array_filter(array_map('trim', explode(',', strtoupper($z['countries']))))),
                    'cost' => (float)$z['cost'],
                    'freeThreshold' => $z['freeThreshold'] !== null ? (float)$z['freeThreshold'] : null,
                    'deliveryDays' => ($en && $z['deliveryDays_en']) ? $z['deliveryDays_en'] : $z['deliveryDays'],
                ];
            }, $zones),
            'payments' => [
                'stripe' => (bool)self::setting('stripe', 'secret_key'),
                'paypal' => (bool)self::setting('paypal', 'client_id'),
            ],
        ];
    }
}
