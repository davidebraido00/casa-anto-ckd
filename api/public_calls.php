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
    ckd_require_class('Email');
    ckd_require_class('User');

    $db_connection = new Database();
    $conn = $db_connection->dbConnection();


    if(isset($_GET['action'])) {
        $action = htmlspecialchars($_GET['action']);
        $input_data = json_decode(file_get_contents("php://input"));

        include __DIR__ . '/../custom/public_calls.php';
    }
    else {
        $body_response['message'] = "Missing action.";
        $body_response['success'] = 0;
        $body_response['status'] = 400;
        $body_response['error_code'] = "apiPublic.002";
    }
    

    utf8_encode_deep($body_response);
    print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);
    
    die;
?>