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
    ckd_require_class('User');
    ckd_require_class('Email');
    ckd_require_class('LoginSocial');


    $db_connection = new Database();
    $conn = $db_connection->dbConnection();

    
    if(isset($_GET['action'])) {
        $action = htmlspecialchars($_GET['action']);
        $input_data = json_decode(file_get_contents("php://input"));

        if($action == "login") {
            $user = new User($conn, $input_data);
            $body_response = $user->fetchLogin();
        }
        else if($action == "logout") {
            $user = new User($conn, $input_data);
            $body_response = $user->fetchLogout();
        }
        else if($action == "checkLogin") {
            $user = new User($conn, $input_data);
            $body_response = $user->fetchCheckLogin();
        }
        else if($action == "refreshSid") {
            $user = new User($conn, $input_data);
            $body_response = $user->fetchRefreshSid();
        }
        else if($action == "forgot") {
            $user = new User($conn, $input_data);
            $body_response = $user->fetchForgot();
        }
        else if($action == "reset") {
            $user = new User($conn, $input_data);
            $body_response = $user->fetchReset();
        }
        else if($action == "google_login") {
            $social = new LoginSocial($conn, $input_data);
            $body_response = $social->fetchGoogleLogin();
        }
        else if($action == "facebook_login") {
            $social = new LoginSocial($conn, $input_data);
            $body_response = $social->fetchFacebookLogin();
        }
        else {
            $body_response['message'] = "Api for user not found. ({$action})";
            $body_response['success'] = 0;
            $body_response['status'] = 404;
            $body_response['error_code'] = "apiUser.001";
        }
    }
    else {
        $body_response['message'] = "Missing action.";
        $body_response['success'] = 0;
        $body_response['status'] = 400;
        $body_response['error_code'] = "apiUser.002";
    }
    

    utf8_encode_deep($body_response);
    print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);
    
    die;
?>