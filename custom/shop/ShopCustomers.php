<?php
require_once __DIR__ . '/ShopBase.php';
require_once __DIR__ . '/ShopMailer.php';
require_once __DIR__ . '/ShopOrders.php';

/**
 * Account dei clienti del sito, separati dagli utenti del backoffice.
 * Password con password_hash(); sessioni con token casuale salvato come
 * sha256 in customer_token (il token in chiaro vive solo nel cookie
 * httpOnly del sito).
 */
class ShopCustomers extends ShopBase {

    const SESSION_DAYS = 30;

    private static function hashToken($t) {
        return hash('sha256', (string)$t);
    }

    private function publicCustomer($c) {
        return [
            'id' => (int)$c['id'],
            'email' => $c['email'],
            'firstName' => $c['firstName'],
            'lastName' => $c['lastName'],
            'phone' => $c['phone'],
            'language' => $c['language'] ?: 'it',
            'marketingConsent' => (int)$c['marketingConsent'] === 1,
        ];
    }

    private function createSession($customerId) {
        $token = self::randomToken(32);
        $this->insert('customer_token', [
            'idCustomer' => $customerId,
            'token' => self::hashToken($token),
            'type' => 'session',
            'expiresAt' => date('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400),
        ]);
        $this->exec("UPDATE customer SET lastLoginAt = NOW() WHERE id = :id", [':id' => $customerId]);
        // pulizia token scaduti
        $this->exec("DELETE FROM customer_token WHERE expiresAt < NOW()");
        return $token;
    }

    /** Cliente dal token di sessione, o null */
    public function fromToken($token) {
        if (!$token || !is_string($token)) return null;
        return $this->one(
            "SELECT c.* FROM customer_token t JOIN customer c ON c.id = t.idCustomer
             WHERE t.token = :t AND t.type = 'session' AND t.expiresAt > NOW() AND t.deleted = 0
               AND c.deleted = 0 AND COALESCE(c.disabled, 0) = 0",
            [':t' => self::hashToken($token)]
        );
    }

    public function requireCustomer($token) {
        $c = $this->fromToken($token);
        if (!$c) throw new ShopException('Sessione scaduta, accedi di nuovo.', 'customer.not_logged');
        return $c;
    }

    private static function validPassword($p) {
        return is_string($p) && mb_strlen($p) >= 8;
    }

    public function register(array $in) {
        $email = strtolower(trim($in['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ShopException('Email non valida.', 'customer.invalid_email');
        if (!self::validPassword($in['password'] ?? null)) throw new ShopException('La password deve avere almeno 8 caratteri.', 'customer.weak_password');
        if (trim($in['firstName'] ?? '') === '') throw new ShopException('Il nome è obbligatorio.', 'customer.missing_name');
        $exists = $this->one("SELECT id FROM customer WHERE email = :e AND deleted = 0", [':e' => $email]);
        if ($exists) throw new ShopException('Esiste già un account con questa email.', 'customer.exists');

        $id = $this->insert('customer', [
            'firstName' => trim($in['firstName']),
            'lastName' => trim($in['lastName'] ?? ''),
            'email' => $email,
            'phone' => trim($in['phone'] ?? ''),
            'passwordHash' => password_hash($in['password'], PASSWORD_DEFAULT),
            'language' => self::lang($in['language'] ?? 'it'),
            'marketingConsent' => !empty($in['marketingConsent']) ? 1 : 0,
            'disabled' => 0,
        ]);
        $customer = $this->one("SELECT * FROM customer WHERE id = :id", [':id' => $id]);
        (new ShopMailer($this->db))->welcome($customer);
        return ['token' => $this->createSession($id), 'customer' => $this->publicCustomer($customer)];
    }

    public function login($email, $password) {
        $email = strtolower(trim((string)$email));
        $c = $this->one("SELECT * FROM customer WHERE email = :e AND deleted = 0", [':e' => $email]);
        if (!$c || !$c['passwordHash'] || !password_verify((string)$password, $c['passwordHash'])) {
            throw new ShopException('Email o password non corretti.', 'customer.bad_credentials');
        }
        if ((int)$c['disabled'] === 1) throw new ShopException('Account disabilitato.', 'customer.disabled');
        return ['token' => $this->createSession($c['id']), 'customer' => $this->publicCustomer($c)];
    }

    public function logout($token) {
        if ($token) $this->exec("DELETE FROM customer_token WHERE token = :t", [':t' => self::hashToken($token)]);
        return true;
    }

    public function me($token) {
        $c = $this->requireCustomer($token);
        return ['customer' => $this->publicCustomer($c), 'addresses' => $this->addresses($c['id'])];
    }

    public function updateProfile($token, array $in) {
        $c = $this->requireCustomer($token);
        $data = [];
        foreach (['firstName', 'lastName', 'phone'] as $k) if (isset($in[$k])) $data[$k] = trim($in[$k]);
        if (isset($in['language'])) $data['language'] = self::lang($in['language']);
        if (isset($in['marketingConsent'])) $data['marketingConsent'] = $in['marketingConsent'] ? 1 : 0;
        if ($data) $this->update('customer', $c['id'], $data);
        return $this->me($token);
    }

    public function changePassword($token, $current, $new) {
        $c = $this->requireCustomer($token);
        if (!password_verify((string)$current, (string)$c['passwordHash'])) throw new ShopException('La password attuale non è corretta.', 'customer.bad_password');
        if (!self::validPassword($new)) throw new ShopException('La nuova password deve avere almeno 8 caratteri.', 'customer.weak_password');
        $this->update('customer', $c['id'], ['passwordHash' => password_hash($new, PASSWORD_DEFAULT)]);
        // chiude le altre sessioni
        $this->exec("DELETE FROM customer_token WHERE idCustomer = :id AND token <> :t", [':id' => $c['id'], ':t' => self::hashToken($token)]);
        return true;
    }

    /** Sempre "ok" per non rivelare quali email sono registrate */
    public function forgot($email) {
        $email = strtolower(trim((string)$email));
        $c = $this->one("SELECT * FROM customer WHERE email = :e AND deleted = 0 AND COALESCE(disabled, 0) = 0", [':e' => $email]);
        if ($c) {
            $this->exec("DELETE FROM customer_token WHERE idCustomer = :id AND type = 'reset'", [':id' => $c['id']]);
            $token = self::randomToken(32);
            $this->insert('customer_token', [
                'idCustomer' => $c['id'],
                'token' => self::hashToken($token),
                'type' => 'reset',
                'expiresAt' => date('Y-m-d H:i:s', time() + 7200),
            ]);
            (new ShopMailer($this->db))->passwordReset($c, $token);
        }
        return true;
    }

    public function reset($token, $password) {
        if (!self::validPassword($password)) throw new ShopException('La password deve avere almeno 8 caratteri.', 'customer.weak_password');
        $row = $this->one(
            "SELECT * FROM customer_token WHERE token = :t AND type = 'reset' AND expiresAt > NOW() AND deleted = 0",
            [':t' => self::hashToken($token)]
        );
        if (!$row) throw new ShopException('Il link non è valido o è scaduto.', 'customer.reset_invalid');
        $this->update('customer', $row['idCustomer'], ['passwordHash' => password_hash($password, PASSWORD_DEFAULT)]);
        $this->exec("DELETE FROM customer_token WHERE idCustomer = :id", [':id' => $row['idCustomer']]);
        $c = $this->one("SELECT * FROM customer WHERE id = :id", [':id' => $row['idCustomer']]);
        return ['token' => $this->createSession($c['id']), 'customer' => $this->publicCustomer($c)];
    }

    // --- Indirizzi ---------------------------------------------------------

    public function addresses($customerId) {
        $rows = $this->all("SELECT * FROM customer_address WHERE idCustomer = :id AND deleted = 0 ORDER BY isDefault DESC, id DESC", [':id' => $customerId]);
        return array_map(function ($a) {
            return [
                'id' => (int)$a['id'], 'label' => $a['label'], 'firstName' => $a['firstName'], 'lastName' => $a['lastName'],
                'company' => $a['company'], 'address' => $a['address'], 'city' => $a['city'], 'zip' => $a['zip'],
                'province' => $a['province'], 'country' => $a['country'], 'phone' => $a['phone'],
                'isDefault' => (int)$a['isDefault'] === 1,
            ];
        }, $rows);
    }

    public function saveAddress($token, array $a) {
        $c = $this->requireCustomer($token);
        foreach (['address', 'city', 'zip', 'country'] as $k) {
            if (trim($a[$k] ?? '') === '') throw new ShopException("Campo obbligatorio mancante: {$k}", 'address.missing_' . $k);
        }
        $data = [];
        foreach (['label', 'firstName', 'lastName', 'company', 'address', 'city', 'zip', 'province', 'phone'] as $k) $data[$k] = trim($a[$k] ?? '');
        $data['country'] = strtoupper(trim($a['country']));
        $data['isDefault'] = !empty($a['isDefault']) ? 1 : 0;
        $count = (int)$this->value("SELECT COUNT(*) FROM customer_address WHERE idCustomer = :id AND deleted = 0", [':id' => $c['id']]);
        if ($count === 0) $data['isDefault'] = 1;
        if ($data['isDefault']) $this->exec("UPDATE customer_address SET isDefault = 0 WHERE idCustomer = :id", [':id' => $c['id']]);

        if (!empty($a['id'])) {
            $own = $this->one("SELECT id FROM customer_address WHERE id = :a AND idCustomer = :c AND deleted = 0", [':a' => (int)$a['id'], ':c' => $c['id']]);
            if (!$own) throw new ShopException('Indirizzo non trovato.', 'address.not_found');
            $this->update('customer_address', (int)$a['id'], $data);
        } else {
            $data['idCustomer'] = $c['id'];
            $this->insert('customer_address', $data);
        }
        return $this->addresses($c['id']);
    }

    public function deleteAddress($token, $id) {
        $c = $this->requireCustomer($token);
        $this->exec("UPDATE customer_address SET deleted = 1 WHERE id = :a AND idCustomer = :c", [':a' => (int)$id, ':c' => $c['id']]);
        return $this->addresses($c['id']);
    }

    // --- Ordini ------------------------------------------------------------

    public function orders($token) {
        $c = $this->requireCustomer($token);
        $rows = $this->all(
            "SELECT o.id, o.number, o.date, o.status, o.total,
                    (SELECT SUM(quantity) FROM shop_order_item i WHERE i.idOrder = o.id AND i.deleted = 0) AS items
             FROM shop_order o
             WHERE o.deleted = 0 AND o.channel = 'online' AND o.idCustomer = :id
               AND o.status <> 'in_attesa_pagamento'
             ORDER BY o.date DESC",
            [':id' => $c['id']]
        );
        return array_map(function ($o) {
            return ['number' => $o['number'], 'date' => $o['date'], 'status' => $o['status'], 'total' => (float)$o['total'], 'items' => (int)$o['items']];
        }, $rows);
    }

    public function order($token, $number) {
        $c = $this->requireCustomer($token);
        $o = $this->one(
            "SELECT * FROM shop_order WHERE number = :n AND deleted = 0 AND idCustomer = :id",
            [':n' => $number, ':id' => $c['id']]
        );
        if (!$o) throw new ShopException('Ordine non trovato.', 'order.not_found');
        $view = (new ShopOrders($this->db))->publicView($o);
        // prodotti già recensiti da questo cliente
        $reviewed = $this->all("SELECT idProduct FROM review WHERE idCustomer = :c AND deleted = 0", [':c' => $c['id']]);
        $view['reviewedProducts'] = array_map('intval', array_column($reviewed, 'idProduct'));
        $ids = array_filter(array_column($view['items'], 'variantId'));
        $map = [];
        if ($ids) {
            foreach ($this->all("SELECT v.id, p.id AS productId, p.slug FROM product_variant v JOIN product p ON p.id = v.idProduct WHERE v.id IN (" . implode(',', array_map('intval', $ids)) . ")") as $r) {
                $map[(int)$r['id']] = $r;
            }
        }
        foreach ($view['items'] as &$it) {
            $it['productId'] = isset($map[$it['variantId']]) ? (int)$map[$it['variantId']]['productId'] : null;
            $it['slug'] = $map[$it['variantId']]['slug'] ?? null;
        }
        return $view;
    }
}
