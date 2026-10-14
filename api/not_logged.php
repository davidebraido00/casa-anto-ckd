<?php

    /*
        verifico settings per capire se le chiamate possono essere non autenticate
    */

    $tables = json_decode(file_get_contents("../config/settings.json"), true);
    if (array_key_exists('not_loged', $tables)) {
        $not_logged_settings = $tables['not_loged'];

        if($not_logged_settings['calls_allowed'] === true) {
            $resp = array(
                'level' => $not_logged_settings['level'],
                'id' => $not_logged_settings['id'],
                'idUserGroup' => $not_logged_settings['idUserGroup'],
                'username' => $not_logged_settings['username'],
                'name' => $not_logged_settings['name'],
                'surname' => $not_logged_settings['surname']
            );
            $user_info = json_encode($resp);
        }
        else {
            $body_response['message'] = "Not logged.";
            $body_response['success'] = 0;
            $body_response['status'] = 400;
            $body_response['error_code'] = "not.logged";
            print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);
            exit;
            die;
        }
    }
    else {
        $body_response['message'] = "Not logged.";
        $body_response['success'] = 0;
        $body_response['status'] = 400;
        $body_response['error_code'] = "not.logged";
        print json_encode($body_response, true, JSON_UNESCAPED_SLASHES);
        exit;
        die;
    }

?>