<?php
require_once __DIR__ . '/ShopBase.php';

/**
 * Email transazionali del negozio (IT/EN).
 *
 * Se mail.smtp_server è vuoto, o mail.dev_log è true, le email non vengono
 * spedite ma salvate come .html in custom/logs/mail/ (anteprima in sviluppo).
 */
class ShopMailer extends ShopBase {

    private static $T = [
        'it' => [
            'hello' => 'Ciao',
            'order_subject' => 'Conferma ordine %s',
            'order_intro' => 'grazie per il tuo ordine! Lo stiamo preparando con cura. Ecco il riepilogo:',
            'product' => 'Prodotto', 'qty' => 'Q.tà', 'price' => 'Prezzo',
            'subtotal' => 'Subtotale', 'discount' => 'Sconto', 'shipping' => 'Spedizione', 'total' => 'Totale',
            'free' => 'Gratuita',
            'ship_to' => 'Indirizzo di spedizione',
            'view_order' => 'Vedi il tuo ordine',
            'shipped_subject' => 'Il tuo ordine %s è stato spedito',
            'shipped_intro' => 'il tuo ordine è in viaggio!',
            'tracking' => 'Numero di tracciamento',
            'track' => 'Traccia la spedizione',
            'reset_subject' => 'Reimposta la password',
            'reset_intro' => 'abbiamo ricevuto una richiesta di reimpostare la password del tuo account. Il link è valido per 2 ore.',
            'reset_cta' => 'Reimposta la password',
            'reset_ignore' => 'Se non sei stato tu, ignora questa email.',
            'back_subject' => '%s è di nuovo disponibile',
            'back_intro' => 'il prodotto che aspettavi è di nuovo disponibile:',
            'back_cta' => 'Acquista ora',
            'welcome_subject' => 'Benvenuto in Casa Anto',
            'welcome_intro' => 'il tuo account è stato creato. Da ora puoi seguire i tuoi ordini e riordinare in un attimo.',
            'account_cta' => 'Vai al tuo account',
            'footer' => 'Olio extravergine d\'oliva · Vittorio Veneto (TV)',
        ],
        'en' => [
            'hello' => 'Hi',
            'order_subject' => 'Order confirmation %s',
            'order_intro' => 'thank you for your order! We are preparing it with care. Here is your summary:',
            'product' => 'Product', 'qty' => 'Qty', 'price' => 'Price',
            'subtotal' => 'Subtotal', 'discount' => 'Discount', 'shipping' => 'Shipping', 'total' => 'Total',
            'free' => 'Free',
            'ship_to' => 'Shipping address',
            'view_order' => 'View your order',
            'shipped_subject' => 'Your order %s has shipped',
            'shipped_intro' => 'your order is on its way!',
            'tracking' => 'Tracking number',
            'track' => 'Track your parcel',
            'reset_subject' => 'Reset your password',
            'reset_intro' => 'we received a request to reset your account password. The link is valid for 2 hours.',
            'reset_cta' => 'Reset password',
            'reset_ignore' => 'If this wasn\'t you, just ignore this email.',
            'back_subject' => '%s is back in stock',
            'back_intro' => 'the product you were waiting for is available again:',
            'back_cta' => 'Shop now',
            'welcome_subject' => 'Welcome to Casa Anto',
            'welcome_intro' => 'your account has been created. You can now follow your orders and reorder in a click.',
            'account_cta' => 'Go to your account',
            'footer' => 'Extra virgin olive oil · Vittorio Veneto (TV), Italy',
        ],
    ];

    private function t($lang, $key) {
        $lang = self::lang($lang);
        return self::$T[$lang][$key] ?? self::$T['it'][$key] ?? $key;
    }

    private static function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    private static function eur($v) {
        return '€ ' . number_format((float)$v, 2, ',', '.');
    }

    // --- Template -----------------------------------------------------------

    private function layout($lang, $content) {
        $footer = self::e($this->t($lang, 'footer'));
        $site = self::e(self::siteUrl());
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;background:#d8dcdf;font-family:Helvetica,Arial,sans-serif;color:#000000">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#d8dcdf;padding:32px 12px"><tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #d8dcdf">'
            . '<tr><td style="padding:28px 32px;border-bottom:1px solid #d8dcdf;text-align:center">'
            . '<a href="' . $site . '" style="text-decoration:none;color:#000000;font-size:20px;font-weight:bold;letter-spacing:4px">CASA ANTO</a></td></tr>'
            . '<tr><td style="padding:32px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6">' . $content . '</td></tr>'
            . '<tr><td style="padding:20px 32px;border-top:1px solid #d8dcdf;text-align:center;font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#000000">'
            . $footer . '</td></tr></table></td></tr></table></body></html>';
    }

    private function button($url, $label) {
        return '<p style="margin:28px 0"><a href="' . self::e($url) . '" style="background:#000000;color:#fff;text-decoration:none;padding:12px 24px;display:inline-block;font-family:Helvetica,Arial,sans-serif;font-size:14px;letter-spacing:1px">'
            . self::e($label) . '</a></p>';
    }

    private function orderTable($order, $items, $lang) {
        $rows = '';
        foreach ($items as $it) {
            $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee">' . self::e($it['productName']) . ' <span style="color:#000000">' . self::e($it['format']) . '</span></td>'
                . '<td align="center" style="padding:8px 0;border-bottom:1px solid #eee">' . (int)$it['quantity'] . '</td>'
                . '<td align="right" style="padding:8px 0;border-bottom:1px solid #eee">' . self::eur($it['lineTotal']) . '</td></tr>';
        }
        $tot = function ($label, $value, $bold = false) {
            $s = $bold ? 'font-weight:bold;font-size:16px;' : '';
            return '<tr><td colspan="2" align="right" style="padding:4px 12px 4px 0;' . $s . '">' . self::e($label) . '</td><td align="right" style="padding:4px 0;' . $s . '">' . $value . '</td></tr>';
        };
        $html = '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin:16px 0">'
            . '<tr style="color:#000000;font-size:12px;text-transform:uppercase"><td>' . $this->t($lang, 'product') . '</td><td align="center">' . $this->t($lang, 'qty') . '</td><td align="right">' . $this->t($lang, 'price') . '</td></tr>'
            . $rows
            . $tot($this->t($lang, 'subtotal'), self::eur($order['subtotal']));
        if ((float)$order['discountAmount'] > 0) {
            $html .= $tot($this->t($lang, 'discount') . ($order['discountCode'] ? ' (' . $order['discountCode'] . ')' : ''), '− ' . self::eur($order['discountAmount']));
        }
        $html .= $tot($this->t($lang, 'shipping'), (float)$order['shippingCost'] > 0 ? self::eur($order['shippingCost']) : self::e($this->t($lang, 'free')))
            . $tot($this->t($lang, 'total'), self::eur($order['total']), true)
            . '</table>';
        return $html;
    }

    private function address($order) {
        $parts = array_filter([
            trim(($order['firstName'] ?? '') . ' ' . ($order['lastName'] ?? '')),
            $order['shipAddress'] ?? null,
            trim(($order['shipZip'] ?? '') . ' ' . ($order['shipCity'] ?? '') . ($order['shipProvince'] ? ' (' . $order['shipProvince'] . ')' : '')),
            $order['shipCountry'] ?? null,
        ]);
        return implode('<br>', array_map([self::class, 'e'], $parts));
    }

    public function orderUrl($order) {
        $lang = self::lang($order['language'] ?? 'it');
        return self::siteUrl() . '/' . $lang . '/ordine/' . rawurlencode($order['number']) . '?t=' . rawurlencode($order['accessToken']);
    }

    // --- Email --------------------------------------------------------------

    public function orderConfirmation($order, $items) {
        $lang = $order['language'] ?? 'it';
        $html = '<p>' . $this->t($lang, 'hello') . ' ' . self::e($order['firstName']) . ',</p>'
            . '<p>' . $this->t($lang, 'order_intro') . '</p>'
            . '<p style="font-size:13px;color:#000000">#' . self::e($order['number']) . '</p>'
            . $this->orderTable($order, $items, $lang);
        if (!empty($order['shipAddress'])) {
            $html .= '<p style="margin-top:24px"><strong>' . $this->t($lang, 'ship_to') . '</strong><br>' . $this->address($order) . '</p>';
        }
        $html .= $this->button($this->orderUrl($order), $this->t($lang, 'view_order'));
        return $this->send($order['email'], trim($order['firstName'] . ' ' . $order['lastName']),
            sprintf($this->t($lang, 'order_subject'), $order['number']), $this->layout($lang, $html));
    }

    public function newOrderNotification($order, $items) {
        $to = $this->shopSettings()['notificationEmail'] ?? null;
        if (!$to) return null;
        $html = '<p><strong>Nuovo ordine ' . self::e($order['number']) . '</strong> (' . self::e($order['channel']) . ', ' . self::e($order['paymentMethod']) . ')</p>'
            . '<p>' . self::e($order['firstName'] . ' ' . $order['lastName']) . ' — ' . self::e($order['email']) . ' ' . self::e($order['phone']) . '</p>'
            . $this->orderTable($order, $items, 'it');
        if (!empty($order['shipAddress'])) $html .= '<p>' . $this->address($order) . '</p>';
        if (!empty($order['customerNotes'])) $html .= '<p><em>Note del cliente:</em> ' . nl2br(self::e($order['customerNotes'])) . '</p>';
        return $this->send($to, 'Casa Anto', 'Nuovo ordine ' . $order['number'] . ' — ' . self::eur($order['total']), $this->layout('it', $html));
    }

    public function orderShipped($order) {
        $lang = $order['language'] ?? 'it';
        $html = '<p>' . $this->t($lang, 'hello') . ' ' . self::e($order['firstName']) . ',</p>'
            . '<p>' . $this->t($lang, 'shipped_intro') . '</p>';
        if (!empty($order['trackingNumber'])) {
            $html .= '<p><strong>' . $this->t($lang, 'tracking') . ':</strong> ' . self::e(trim(($order['trackingCarrier'] ?? '') . ' ' . $order['trackingNumber'])) . '</p>';
        }
        if (!empty($order['trackingUrl'])) $html .= $this->button($order['trackingUrl'], $this->t($lang, 'track'));
        else $html .= $this->button($this->orderUrl($order), $this->t($lang, 'view_order'));
        return $this->send($order['email'], trim($order['firstName'] . ' ' . $order['lastName']),
            sprintf($this->t($lang, 'shipped_subject'), $order['number']), $this->layout($lang, $html));
    }

    public function passwordReset($customer, $token) {
        $lang = self::lang($customer['language'] ?? 'it');
        $url = self::siteUrl() . '/' . $lang . '/account/reimposta-password?token=' . rawurlencode($token);
        $html = '<p>' . $this->t($lang, 'hello') . ' ' . self::e($customer['firstName']) . ',</p>'
            . '<p>' . $this->t($lang, 'reset_intro') . '</p>'
            . $this->button($url, $this->t($lang, 'reset_cta'))
            . '<p style="color:#000000;font-size:13px">' . $this->t($lang, 'reset_ignore') . '</p>';
        return $this->send($customer['email'], $customer['firstName'], $this->t($lang, 'reset_subject'), $this->layout($lang, $html));
    }

    public function welcome($customer) {
        $lang = self::lang($customer['language'] ?? 'it');
        $html = '<p>' . $this->t($lang, 'hello') . ' ' . self::e($customer['firstName']) . ',</p>'
            . '<p>' . $this->t($lang, 'welcome_intro') . '</p>'
            . $this->button(self::siteUrl() . '/' . $lang . '/account', $this->t($lang, 'account_cta'));
        return $this->send($customer['email'], $customer['firstName'], $this->t($lang, 'welcome_subject'), $this->layout($lang, $html));
    }

    public function backInStock($email, $lang, $productName, $slug) {
        $lang = self::lang($lang);
        $url = self::siteUrl() . '/' . $lang . '/prodotti/' . rawurlencode($slug);
        $html = '<p>' . $this->t($lang, 'hello') . ',</p>'
            . '<p>' . $this->t($lang, 'back_intro') . '</p>'
            . '<p style="font-size:20px;font-family:Helvetica,Arial,sans-serif;letter-spacing:1px">' . self::e($productName) . '</p>'
            . $this->button($url, $this->t($lang, 'back_cta'));
        return $this->send($email, '', sprintf($this->t($lang, 'back_subject'), $productName), $this->layout($lang, $html));
    }

    // --- Invio --------------------------------------------------------------

    private function send($to, $name, $subject, $html) {
        $mail = self::setting('mail');
        if (empty($mail['smtp_server']) || !empty($mail['dev_log'])) {
            $dir = dirname(__DIR__) . '/logs/mail';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $file = $dir . '/' . date('Ymd-His') . '-' . substr(md5($to . $subject . microtime()), 0, 6) . '.html';
            @file_put_contents($file, "<!-- To: {$to} | Subject: {$subject} -->\n" . $html);
            return ['success' => 1, 'logged' => basename($file)];
        }
        try {
            $email = new Email($this->db, json_encode([]));
            return $email->sendMessage($to, $name, $subject, $html);
        } catch (Throwable $e) {
            return ['success' => 0, 'message' => $e->getMessage()];
        }
    }
}
