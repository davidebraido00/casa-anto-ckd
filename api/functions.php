<?php

    function utf8_encode_deep(&$input) {
        if (is_string($input)) {
            //$input = utf8_encode($input); !!! deprecated in php 8.2
            $input = mb_convert_encoding($input, "UTF-8", "UTF-8, ISO-8859-9");
        } else if (is_array($input)) {
            foreach ($input as &$value) {
                utf8_encode_deep($value);
            }

            unset($value);
        } else if (is_object($input)) {
            $vars = array_keys(get_object_vars($input));

            foreach ($vars as $var) {
                utf8_encode_deep($input->$var);
            }
        }
    }

/**
 * Carica una classe standard del framework, con override di progetto.
 *
 * Se esiste custom/override/<Nome>.php viene caricata QUELLA al posto della
 * versione in api/classes/. Gli override sono casi limite da evitare quando
 * possibile: per la logica di progetto si usano le classi custom in
 * custom/classes/. Un override va mantenuto manualmente allineato alla classe
 * standard a ogni aggiornamento del framework.
 *
 * Nota: un file di override NON può usare require relativi alla propria
 * directory per raggiungere le classi standard; usare
 * dirname(__DIR__, 2) . '/api/classes/Nome.php'.
 */
/**
 * Carica una classe standard, o il suo override di progetto se esiste.
 * `$name` puo' contenere un sottopercorso (es. 'ai/AiAssistant' ->
 * api/classes/ai/AiAssistant.php, override in custom/override/ai/AiAssistant.php).
 */
function ckd_require_class($name) {
    $override = __DIR__ . '/../custom/override/' . $name . '.php';
    if (file_exists($override)) {
        require_once $override;
        return;
    }
    require_once __DIR__ . '/classes/' . $name . '.php';
}

/**
 * Valida e normalizza il parametro `order` delle API di lettura.
 *
 * Il client manda una stringa che finisce dopo ORDER BY: storicamente
 * "campo DESC", dal multi-sort anche "campo DESC, altro ASC". Ogni
 * segmento deve essere `nome_campo [ASC|DESC]`; qualsiasi altra cosa
 * invalida l'intera stringa (ritorna null e il chiamante tiene il
 * default), così il parametro non è più una superficie di injection.
 *
 * $prefix ("e.") serve alle query di ricerca con join: viene applicato
 * a OGNI campo, non solo al primo.
 */
function ckd_sanitize_order($orderString, $prefix = '') {
    $parts = explode(',', strval($orderString));
    $clean = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') return null;
        if (!preg_match('/^([A-Za-z0-9_]+)(\s+(ASC|DESC))?$/i', $part, $m)) {
            return null;
        }
        $direction = isset($m[3]) ? strtoupper($m[3]) : '';
        $clean[] = $prefix . $m[1] . ($direction !== '' ? ' ' . $direction : '');
    }
    return count($clean) > 0 ? implode(', ', $clean) : null;
}
