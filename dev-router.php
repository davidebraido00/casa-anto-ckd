<?php
/**
 * Router per il server PHP integrato, SOLO per lo sviluppo locale.
 * Replica le regole di api/.htaccess, che `php -S` ignora.
 *
 *   php -S localhost:8080 dev-router.php
 *
 * In produzione (Apache) questo file non viene usato.
 */

// PHP 8.5 segnala come deprecate alcune costanti usate dal core
// (PDO::MYSQL_ATTR_LOCAL_INFILE): l'avviso stampato romperebbe il JSON.
set_error_handler(function () { return true; }, E_DEPRECATED | E_USER_DEPRECATED);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/api/([^/]+)/(.*)$#', $uri, $m)) {
    $routes = [
        'get_item'            => ['dispatcher.php', 'action=get_item&path='],
        'get_items'           => ['dispatcher.php', 'action=get_items&path='],
        'search_items'        => ['dispatcher.php', 'action=search_items&path='],
        'get_item_simple'     => ['dispatcher.php', 'action=get_item_simple&path='],
        'get_items_simple'    => ['dispatcher.php', 'action=get_items_simple&path='],
        'search_items_simple' => ['dispatcher.php', 'action=search_items_simple&path='],
        'post'                => ['dispatcher.php', 'action=post&path='],
        'delete'              => ['dispatcher.php', 'action=delete&path='],
        'auth'                => ['user.php', 'action='],
        'account'             => ['profile.php', 'action='],
        'users'               => ['users_functions.php', 'action='],
        'resource'            => ['file.php', 'action='],
        'custom'              => ['custom_calls.php', 'action='],
        'analytics'           => ['analytics.php', 'action='],
        'ai'                  => ['ai.php', 'action='],
        'external_api'        => ['external_api.php', 'action='],
        'utils'               => ['utils.php', 'action='],
        'public'              => ['public_calls.php', 'action='],
    ];
    $key = strtolower($m[1]);
    if (isset($routes[$key])) {
        [$file, $qs] = $routes[$key];
        parse_str($qs . $m[2], $params);
        $_GET = array_merge($_GET, $params);
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            exit;
        }
        chdir(__DIR__ . '/api');
        require __DIR__ . '/api/' . $file;
        return true;
    }
}

// /utils/ e i file statici (upload/, ecc.)
$path = __DIR__ . $uri;
if (is_dir($path) && is_file(rtrim($path, '/') . '/index.php')) {
    chdir($path);
    require rtrim($path, '/') . '/index.php';
    return true;
}
if (preg_match('#\.php$#', $uri) && is_file($path)) {
    chdir(dirname($path));
    require $path;
    return true;
}
return false;
