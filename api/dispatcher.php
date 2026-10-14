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
    ckd_require_class('Get');
    ckd_require_class('GetSimple');
    ckd_require_class('Post');
    ckd_require_class('Delete');
    ckd_require_class('Email');


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
        include 'not_logged.php';
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

        if($action == "get_item") {
            $path = htmlspecialchars($_GET['path']);
            $get = new Get($conn, $user_info, $path, $input_data);
            $body_response = $get->fetchGetItem();
        }
        else if($action == "get_items") {
            $path = htmlspecialchars($_GET['path']);
            $get = new Get($conn, $user_info, $path, $input_data);
            $body_response = $get->fetchGetItems();
        }
        else if($action == "search_items") {
            $path = htmlspecialchars($_GET['path']);
            $get = new Get($conn, $user_info, $path, $input_data);
            $body_response = $get->fetchSearchItems();
        }
        else if($action == "get_item_simple") {
            $path = htmlspecialchars($_GET['path']);
            $get = new GetSimple($conn, $user_info, $path, $input_data);
            $body_response = $get->fetchGetItem();
        }
        else if($action == "get_items_simple") {
            $path = htmlspecialchars($_GET['path']);
            $get = new GetSimple($conn, $user_info, $path, $input_data);
            $body_response = $get->fetchGetItems();
        }
        else if($action == "search_items_simple") {
            $path = htmlspecialchars($_GET['path']);
            $get = new GetSimple($conn, $user_info, $path, $input_data);
            $body_response = $get->fetchSearchItems();
        }
        else if($action == "post") {
            $path = htmlspecialchars($_GET['path']);
            $post = new Post($conn, $user_info, $path, $input_data);
            $body_response = $post->fetchPost();
        }
        else if($action == "delete") {
            $path = htmlspecialchars($_GET['path']);
            $delete = new Delete($conn, $user_info, $path, $input_data);
            $body_response = $delete->fetchDelete();
        }
        else {
            $body_response['message'] = "Api not found." . $_GET['action'];
            $body_response['success'] = 0;
            $body_response['status'] = 404;
            $body_response['error_code'] = "apiDispatcher.001";
        }
    }
    else {
        $body_response['message'] = "Missing action.";
        $body_response['success'] = 0;
        $body_response['status'] = 400;
        $body_response['error_code'] = "apiDispatcher.002";
    }
    

    utf8_encode_deep($body_response);
    print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);

    die;
?>