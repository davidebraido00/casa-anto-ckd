<?php

ckd_require_class('AdvancedAnalytics');

// Initialize Analytics class
$analytics = new AdvancedAnalytics($conn, $user_info, $input_data);

if($action == "overview") {
    $body_response = $analytics->get_overview_analytics();
}
else if($action == "trends") {
    $body_response = $analytics->get_trends_data();
}
else if($action == "entity") {
    $body_response = $analytics->get_entity_analytics();
}
else if($action == "activity") {
    $body_response = $analytics->get_activity_log();
}
else if($action == "sessions") {
    $body_response = $analytics->get_sessions_data();
}
else if($action == "server_info") {
    $body_response = $analytics->get_server_info();
}
else if($action == "export") {
    $body_response = $analytics->export_analytics_report();
}
else {
    $body_response['message'] = "Analytics endpoint '{$action}' not found.";
    $body_response['success'] = 0;
    $body_response['status'] = 404;
}

?>