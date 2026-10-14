<?php

require __DIR__ . '/classes/ShopAdminApi.php';

if (ShopAdminApi::handles($action)) {
    $api = new ShopAdminApi($conn, $user_info, $input_data);
    $body_response = $api->handle($action);
}
else {
    $body_response['message'] = "Api (custom) not found. ({$action})";
    $body_response['success'] = 0;
    $body_response['status'] = 404;
}

?>
