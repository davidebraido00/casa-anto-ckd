<?php

    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: access");
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

    $body_response = array();

    /** functions */
    include 'functions.php';

    /** classes */
    ckd_require_class('Database');
    ckd_require_class('Auth');

    $db_connection = new Database();
    $conn = $db_connection->dbConnection();
    
    $auth = new Auth($conn);
    $user_id = $auth->getUserId();

    if($user_id === "AUTH-REFRESH-SID") {
        $body_response['message'] = "Please refresh sid.";
        $body_response['success'] = 0;
        $body_response['status'] = 401;
        $body_response['error_code'] = 'refresh.sid';
        print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);
        exit;
        die;
    }
    else if($user_id === "NONE") {
        $body_response['message'] = "Not logged in.";
        $body_response['success'] = 0;
        $body_response['status'] = 401;
        $body_response['error_code'] = 'auth.not.logged';
        print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);
        exit;
        die;
    }
    else if($user_id === "USER-DISABLED") {
        $body_response['message'] = "Account disabilitato. Contattare l'amministratore.";
        $body_response['success'] = 0;
        $body_response['status'] = 401;
        $body_response['error_code'] = 'user.disabled';
        print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);
        exit;
        die;
    }
    else {
        $auth_user = $auth->getUser();
        utf8_encode_deep($auth_user);
        $user_info = json_encode($auth_user, true, JSON_UNESCAPED_SLASHES);
    }

    if(isset($_GET['action'])) {
        $action = htmlspecialchars($_GET['action']);
        $input_data = json_decode(file_get_contents("php://input"));

        include 'utils_config.php';
    }
    else {
        $body_response['message'] = "Missing action.";
        $body_response['success'] = 0;
        $body_response['status'] = 400;
        $body_response['error_code'] = "utils.missing.action";
    }
    
    utf8_encode_deep($body_response);
    print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);

    die;
?>