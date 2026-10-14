<?php

/**
 * Tool di progetto dell'assistente AI (contratto in api/classes/ai/AiTools.php).
 * File DEL PROGETTO: l'allineamento del framework non lo tocca.
 *
 * Un tool incapsula una funzione PHP gia' scritta per il progetto (di solito
 * quella dietro una pagina di analisi): il modello la chiama per nome e
 * ottiene ESATTAMENTE i numeri della pagina, invece di ricostruirli in SQL
 * libero con una seconda definizione. Deve solo leggere.
 *
 * Ogni tool:
 *   'name'         ^[a-z][a-z0-9_]{2,40}$, non ui_/run_/search_/distinct_
 *   'description'  cosa fa e quando usarlo: e' quello che legge il modello
 *   'input_schema' JSON Schema dell'input (opzionale, default oggetto vuoto)
 *   'levels'       chi puo' chiamarlo (opzionale, default chi usa l'assistente)
 *   'handler'      function (array $input, PDO $db, array $userInfo): array
 *
 * Esempio:
 *
 *   return [
 *       [
 *           'name' => 'revenue_by_month',
 *           'description' => "Fatturato per mese della pagina Statistiche (imponibile, per data fattura). Usalo per fatturato e andamento mensile.",
 *           'input_schema' => ['type' => 'object', 'properties' => [
 *               'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
 *               'to'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
 *           ]],
 *           'levels' => [0, 1],
 *           'handler' => function (array $input, PDO $db, array $userInfo) {
 *               $s = new Statistics($db, json_encode($userInfo), $input);
 *               return $s->revenueByMonth()['body'];
 *           },
 *       ],
 *   ];
 */

return [];
