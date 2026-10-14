<?php

require __DIR__ . '/public_classes/ShopPublicApi.php';

if (ShopPublicApi::handles($action)) {
    $api = new ShopPublicApi($conn, $input_data);
    $body_response = $api->handle($action);
}
else {
    $body_response['message'] = "Api not found. ({$action})";
    $body_response['success'] = 0;
    $body_response['status'] = 404;
    $body_response['error_code'] = "apiPublic.001";
}

?>
