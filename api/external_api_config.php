<?php

ckd_require_class('GoogleMapsApi');

if($action == "get_google_maps_address") {
    $class = new GoogleMapsApi($conn, $user_info, $input_data);
    $body_response = $class->getAddress();
}
else if($action == "get_google_maps_suggestions") {
    $class = new GoogleMapsApi($conn, $user_info, $input_data);
    $body_response = $class->getSuggestions();
}
else {
    $body_response['message'] = "Api (External Api) not found. ({$action})";
    $body_response['success'] = 0;
    $body_response['status'] = 404;
}

?>