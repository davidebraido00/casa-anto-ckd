<?php

/**
 * Assistente AI: routing di /api/ai/<action>. Le classi stanno in
 * api/classes/ai/ (ckd_require_class accetta il sottopercorso; un eventuale
 * override va in custom/override/ai/<Classe>.php).
 *
 * Il giro agentico lo chiude il client (vedi AiAssistant):
 *   message         una chiamata al modello con la conversazione
 *   tool            esecuzione (o sola validazione) di un tool
 *   export          file Excel con il risultato completo di una query del
 *                   giro (vedi AiExport): non passa dal modello
 *   feedback        pollice su/giu' su una risposta (vedi AiFeedback)
 *   widget_*        widget in dashboard, per utente (vedi AiWidget):
 *                   create (una chiamata al modello), list, run, update, delete
 *   admin_stats     pannello admin: spesa, uso, domande, segnalazioni
 *                   (vedi AiAdmin; livelli in ai_assistant.admin_levels)
 *
 * Storico delle conversazioni, per utente (vedi AiHistory):
 *   history_list    le conversazioni di chi chiama
 *   history_save    upsert di una conversazione
 *   history_delete  cancellazione
 *   history_archive archivia / ripristina
 */

ckd_require_class('ai/AiStore');
ckd_require_class('ai/AiSchema');
ckd_require_class('ai/AiSql');
ckd_require_class('ai/AiTools');
ckd_require_class('ai/AiManual');
ckd_require_class('ai/AiUsage');
ckd_require_class('ai/AiExport');
ckd_require_class('ai/AiFiles');
ckd_require_class('ai/AiFeedback');
ckd_require_class('ai/AiAdmin');
ckd_require_class('ai/AiWidget');
ckd_require_class('ai/AiAssistant');
ckd_require_class('ai/AiHistory');

$ai = new AiAssistant($conn, $user_info, $input_data);

if($action == "message") {
    $body_response = $ai->message();
}
else if($action == "tool") {
    $body_response = $ai->tool();
}
else if($action == "export") {
    $body_response = $ai->export();
}
else if($action == "feedback") {
    $forbidden = $ai->levelGuard();
    if ($forbidden !== null) {
        $body_response = $forbidden;
    }
    else {
        $feedback = new AiFeedback($conn, $user_info);
        $body_response = $feedback->save($input_data);
    }
}
else if(strpos($action, "widget_") === 0) {
    $forbidden = $ai->levelGuard();
    if ($forbidden !== null) {
        $body_response = $forbidden;
    }
    else {
        $widget = new AiWidget($conn, $user_info, $ai);
        if ($action == "widget_create")       $body_response = $widget->create($input_data);
        else if ($action == "widget_list")    $body_response = $widget->list();
        else if ($action == "widget_run")     $body_response = $widget->run($input_data);
        else if ($action == "widget_update")  $body_response = $widget->update($input_data);
        else if ($action == "widget_delete")  $body_response = $widget->delete($input_data);
        else {
            $body_response = ['success' => 0, 'status' => 404, 'error_code' => 'apiAi.404', 'message' => "AI endpoint '{$action}' not found."];
        }
    }
}
else if($action == "admin_stats") {
    $forbidden = $ai->adminGuard();
    if ($forbidden !== null) {
        $body_response = $forbidden;
    }
    else {
        $admin = new AiAdmin($conn, $ai->usage());
        $body_response = $admin->stats($input_data);
    }
}
else if($action == "history_list" || $action == "history_save" || $action == "history_delete" || $action == "history_archive") {
    /* Stessa regola di accesso della chat: lo storico e' di chi puo' usarla. */
    $forbidden = $ai->levelGuard();
    if ($forbidden !== null) {
        $body_response = $forbidden;
    }
    else {
        $history = new AiHistory($conn, $user_info);
        if ($action == "history_list")         $body_response = $history->list($input_data);
        else if ($action == "history_save")    $body_response = $history->save($input_data);
        else if ($action == "history_archive") $body_response = $history->archive($input_data);
        else                                   $body_response = $history->delete($input_data);
    }
}
else {
    $body_response['message'] = "AI endpoint '{$action}' not found.";
    $body_response['success'] = 0;
    $body_response['status'] = 404;
    $body_response['error_code'] = "apiAi.404";
}
