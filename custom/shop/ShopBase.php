<?php
/**
 * Utility condivise dalle classi del negozio: accesso al DB, configurazione,
 * conversione dei parametri e arrotondamenti monetari.
 */
class ShopException extends Exception {
    public $errorCode;
    public $quote = null;
    function __construct($message, $errorCode = 'shop.error') {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}

class ShopBase {
    protected $db;
    protected static $settings = null;
    protected static $shopSettings = null;

    function __construct($db) {
        $this->db = $db;
    }

    /** config/settings.json (il path è relativo a /api/, la cwd delle chiamate) */
    public static function settings() {
        if (self::$settings === null) {
            $path = dirname(__DIR__, 2) . '/config/settings.json';
            self::$settings = json_decode(file_get_contents($path), true);
        }
        return self::$settings;
    }

    public static function setting($section, $key = null, $default = null) {
        $s = self::settings();
        if (!isset($s[$section])) return $default;
        if ($key === null) return $s[$section];
        return $s[$section][$key] ?? $default;
    }

    /** Riga unica di shop_settings */
    public function shopSettings() {
        if (self::$shopSettings === null) {
            $row = $this->one("SELECT * FROM shop_settings WHERE deleted = 0 ORDER BY id LIMIT 1");
            self::$shopSettings = $row ?: ['shopName' => 'Casa Anto', 'orderPrefix' => 'CA'];
        }
        return self::$shopSettings;
    }

    public static function siteUrl() {
        return rtrim(self::setting('shop', 'site_url', 'http://localhost:3000'), '/');
    }

    /** stdClass (da json_decode) → array associativo */
    public static function toArray($data) {
        if ($data === null) return [];
        return json_decode(json_encode($data), true) ?: [];
    }

    public static function money($value) {
        return round((float)$value + 0.0000001, 2);
    }

    public static function lang($value) {
        return $value === 'en' ? 'en' : 'it';
    }

    public static function randomToken($bytes = 32) {
        return bin2hex(random_bytes($bytes));
    }

    // --- PDO helpers -------------------------------------------------------

    public function all($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function one($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function value($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** INSERT con deleted = 0, ritorna l'id */
    public function insert($table, $data) {
        $data['deleted'] = 0;
        $cols = array_keys($data);
        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (:" . implode(', :', $cols) . ")";
        $this->exec($sql, $data);
        return (int)$this->db->lastInsertId();
    }

    public function update($table, $id, $data) {
        $sets = [];
        foreach (array_keys($data) as $c) $sets[] = "`{$c}` = :{$c}";
        $data['__id'] = $id;
        return $this->exec("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE id = :__id", $data);
    }

    // --- Immagini ----------------------------------------------------------

    /** URL pubblico di un campo file (hash) */
    public function fileUrl($hash) {
        if (!$hash) return null;
        $res = $this->one("SELECT entity, hash, extension FROM resource WHERE hash = :h AND deleted = 0 LIMIT 1", [':h' => $hash]);
        if (!$res) return null;
        if (!class_exists('StorageManager')) ckd_require_class('StorageManager');
        return StorageManager::url($res['entity'], $res['hash'] . '.' . $res['extension']);
    }

    // --- Magazzino ---------------------------------------------------------

    public function stockOf($variantId) {
        return (int)$this->value("SELECT COALESCE(SUM(quantity), 0) FROM stock_movement WHERE idVariant = :v AND deleted = 0", [':v' => $variantId]);
    }

    public function stockMap(array $variantIds) {
        $ids = array_values(array_filter(array_map('intval', $variantIds)));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $rows = $this->all("SELECT idVariant, SUM(quantity) AS s FROM stock_movement WHERE deleted = 0 AND idVariant IN ($in) GROUP BY idVariant");
        $map = array_fill_keys($ids, 0);
        foreach ($rows as $r) $map[(int)$r['idVariant']] = (int)$r['s'];
        return $map;
    }

    public static function ok($data = [], $message = '') {
        return array_merge(['success' => 1, 'status' => 200, 'message' => $message], $data);
    }

    public static function fail($message, $code = 'shop.error', $status = 400) {
        return ['success' => 0, 'status' => $status, 'message' => $message, 'error_code' => $code];
    }
}
