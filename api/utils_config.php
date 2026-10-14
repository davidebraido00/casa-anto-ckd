<?php

ckd_require_class('Import');
ckd_require_class('Export');
ckd_require_class('CacheManager');

// ==========================================
// IMPORT/EXPORT ROUTES
// ==========================================

if($action == "import_download_template") {
    $entity = $input_data->entity ?? null;
    if (!$entity) {
        $body_response = ['success' => 0, 'message' => 'Entity not specified'];
    } else {
        try {
            $importClass = new Import($conn, $user_info, $entity, $input_data);
            $body_response = $importClass->downloadTemplate();
        } catch (Exception $e) {
            $body_response = ['success' => 0, 'message' => 'Import error: ' . $e->getMessage()];
        }
    }
}
else if($action == "import_parse_file") {
    $entity = $input_data->entity ?? null;
    if (!$entity) {
        $body_response = ['success' => 0, 'message' => 'Entity not specified'];
    } else {
        try {
            $importClass = new Import($conn, $user_info, $entity, $input_data);
            $body_response = $importClass->parseFile();
        } catch (Exception $e) {
            $body_response = ['success' => 0, 'message' => 'Import error: ' . $e->getMessage()];
        }
    }
}
else if($action == "import_execute") {
    $entity = $input_data->entity ?? null;
    if (!$entity) {
        $body_response = ['success' => 0, 'message' => 'Entity not specified'];
    } else {
        try {
            $importClass = new Import($conn, $user_info, $entity, $input_data);
            $body_response = $importClass->executeImport();
        } catch (Exception $e) {
            $body_response = ['success' => 0, 'message' => 'Import error: ' . $e->getMessage()];
        }
    }
}
else if($action == "export_for_import") {
    $entity = $input_data->entity ?? null;
    if (!$entity) {
        $body_response = ['success' => 0, 'message' => 'Entity not specified'];
    } else {
        try {
            $exportClass = new Export($conn, $user_info, $entity, $input_data);
            $body_response = $exportClass->fetchExportForImport();
        } catch (Exception $e) {
            $body_response = ['success' => 0, 'message' => 'Export error: ' . $e->getMessage()];
        }
    }
}
else if($action == "pullTablesStatus") {
    try {
        $cacheManager = new CacheManager($conn, $user_info, $input_data);
        $body_response = $cacheManager->pullTablesStatus();
    } catch (Exception $e) {
        $body_response = ['success' => 0, 'message' => 'Cache manager error: ' . $e->getMessage()];
    }
}
else if($action == "getDependencyInfo") {
    try {
        $cacheManager = new CacheManager($conn, $user_info, $input_data);
        $body_response = $cacheManager->getDependencyInfo();
    } catch (Exception $e) {
        $body_response = ['success' => 0, 'message' => 'Cache manager error: ' . $e->getMessage()];
    }
}
else {
    $body_response['message'] = "Api (Utils) not found. ({$action})";
    $body_response['success'] = 0;
    $body_response['status'] = 404;
}

?>
