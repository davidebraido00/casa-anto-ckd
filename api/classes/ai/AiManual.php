<?php

    /**
     * Manuale d'uso che l'assistente AI ha davanti per rispondere a "come si
     * fa" e per muoversi nell'interfaccia (tool ui_*).
     *
     * Quattro parti, in quest'ordine:
     *   1. il manuale generico del framework: rotte, cosa si fa in una lista,
     *      in un form, nel dettaglio. E' uguale per ogni progetto CKD;
     *   2. i permessi dell'utente corrente, entita' per entita', letti da
     *      tables.json: cosi' l'assistente non propone di creare un record a
     *      chi non puo', e spiega perche' un'azione non e' permessa;
     *   3. il menu e le etichette del progetto, mandati dal client (stanno in
     *      src/config del frontend, il backend non li conosce): servono a
     *      tradurre "clienti" in `customer` e "vai ai preventivi" in una rotta;
     *   4. il manuale del progetto, `config/ai_manual.md` (DEL PROGETTO, mai
     *      toccato dall'allineamento): flussi di lavoro, significato degli
     *      stati, regole di casa. Markdown libero.
     *
     * Il blocco e' stabile per utente e finisce in cache (cache_control):
     * dalla seconda domanda costa una frazione.
     */
    class AiManual {

        const PROJECT_FILE = '/../../../config/ai_manual.md';
        const MAX_PROJECT_BYTES = 60000;
        const MAX_MENU_ITEMS = 120;
        const MAX_LABELS = 200;

        protected $tables;
        protected $level;

        function __construct($tables, $userInfo) {
            $this->tables = is_array($tables) ? $tables : [];
            $this->level = is_array($userInfo) && isset($userInfo['level']) && is_numeric($userInfo['level'])
                ? (string)(int)$userInfo['level'] : null;
        }

        /**
         * @param array|null $menu    [{label, href, entity?, parent?}] dal client
         * @param array|null $labels  [entity => etichetta] dal client
         */
        public function text($menu = null, $labels = null) {
            $parts = [];
            $parts[] = $this->framework();
            $parts[] = $this->permissions($labels);
            $m = $this->menu($menu);
            if ($m !== '') $parts[] = $m;
            $p = $this->project();
            if ($p !== '') $parts[] = $p;
            return implode("\n\n", $parts);
        }

        // ------------------------------------------------------------------

        private function framework() {
            return "MANUALE DEL GESTIONALE (framework CKD)
Il gestionale e' un'applicazione web: ogni entita' (tabella) ha una lista, un form di inserimento/modifica e una scheda di dettaglio, generati dalla configurazione. Le rotte sono queste (`<entita'>` e' il nome tecnico della tabella, `<id>` l'id del record):
- `/` dashboard.
- `/entity/<entita'>` lista dei record.
- `/entity/<entita'>/add` form di inserimento di un nuovo record.
- `/entity/<entita'>/edit?id=<id>` form di modifica.
- `/entity/<entita'>/clone?id=<id>` nuovo record precompilato copiando quello indicato.
- `/entity/<entita'>/item?id=<id>` scheda di dettaglio del record.
- `/page/<Nome>` pagine speciali del progetto (calendari, kanban, analisi...): sono nel menu.
- `/settings` profilo e password di chi e' collegato. `/users` gestione utenti (amministratori). `/resources` archivio file. `/ai-assistant` questa chat a tutto schermo.

Nella LISTA: casella di ricerca sui campi testuali; filtri per colonna (icona imbuto sull'intestazione), che si possono salvare con un nome e richiamare; ordinamento cliccando l'intestazione (shift+clic per ordinare su piu' colonne); scelta delle colonne visibili; esportazione in Excel/CSV e importazione da CSV (bottoni in alto); selezione multipla per azioni di massa. Ogni riga ha un menu azioni: Dettaglio (si apre in una finestra), Modifica, Clona, Elimina, Log delle modifiche. Il bottone \"Crea\" in alto apre il form di inserimento.

LISTA GIA' FILTRATA VIA URL (utile con `ui_navigate`): la lista legge lo stato dalla query string.
- `?query=<testo>` ricerca testuale; `?sort=<campo> asc|desc` ordinamento; `?page=`, `?limit=`.
- `?f=<campo>:<condizione>:<valore>` un filtro, ripetibile. Condizioni: `value` (uguale), `different_from_value`, `like` (contiene), `start`, `end`, `greater`, `minor`, `range` (`campo:range:min..max`), `in` (`campo:in:1,3,5`), `not_in`, `isnull` (senza valore).
- Esempi: `/entity/payment?f=idContract:value:12` i pagamenti di un contratto; `/entity/customer?f=status:value:lead&sort=name asc`; `/entity/event?f=scheduledAt:range:2026-01-01..2026-01-31`.
Per le foreign key si filtra sull'id (`f=idCustomer:value:<id>`), per le option sul valore tecnico, per i booleani con 1/0.

Nel FORM: i campi obbligatori sono segnati e bloccano il salvataggio se vuoti; i campi che puntano a un'altra entita' (es. il cliente di un preventivo) si scelgono da una finestra di ricerca; i blocchi \"figli\" (es. i servizi di un preventivo, gli allegati) si compilano come righe dentro lo stesso form; il salvataggio e' in fondo. I file si allegano nei campi di tipo file.

Nella SCHEDA di dettaglio si leggono tutti i campi e i record collegati; da li' si passa a Modifica.

L'eliminazione e' logica: il record sparisce dalle liste ma resta nel database (deleted = 1).

I permessi dipendono dal livello dell'utente: se un'azione manca (niente bottone Crea, niente Modifica nel menu) e' perche' il livello non la consente. L'elenco per questo utente e' qui sotto.";
        }

        private function permissions($labels) {
            if ($this->level === null) return "PERMESSI DELL'UTENTE\nLivello non riconosciuto: nessuna entita' accessibile.";

            $lines = [];
            foreach ($this->tables as $table => $def) {
                if (!is_array($def) || !isset($def['permissions']) || !is_array($def['permissions'])) continue;
                $p = isset($def['permissions'][$this->level]) && is_array($def['permissions'][$this->level])
                    ? $def['permissions'][$this->level] : [];
                $view = isset($p['view']) ? $p['view'] : false;
                if ($view === false) continue;

                $ops = [];
                $ops[] = 'vede' . ($view === 'user' ? ' (solo i propri)' : ($view === 'group' ? ' (solo del proprio gruppo)' : ''));
                if (!empty($p['create'])) $ops[] = 'crea';
                if (!empty($p['edit']))   $ops[] = 'modifica';
                if (!empty($p['delete'])) $ops[] = 'elimina';

                $label = is_array($labels) && isset($labels[$table]) && trim((string)$labels[$table]) !== ''
                    ? ' (' . trim((string)$labels[$table]) . ')' : '';
                $lines[] = "- `{$table}`{$label}: " . implode(', ', $ops);
            }

            return "PERMESSI DELL'UTENTE (livello {$this->level})\n"
                . "Per ogni entita' cosa puo' fare. Un'entita' assente qui non e' accessibile: non proporre di aprirla.\n"
                . implode("\n", $lines);
        }

        private function menu($menu) {
            if (!is_array($menu) || !count($menu)) return '';
            $lines = [];
            $n = 0;
            foreach ($menu as $item) {
                if (!is_array($item)) $item = json_decode(json_encode($item), true);
                if (!is_array($item) || !isset($item['label'])) continue;
                $label = trim(mb_substr((string)$item['label'], 0, 60));
                if ($label === '') continue;
                $href = isset($item['href']) ? trim(mb_substr((string)$item['href'], 0, 120)) : '';
                $parent = isset($item['parent']) ? trim(mb_substr((string)$item['parent'], 0, 60)) : '';
                $entity = isset($item['entity']) ? trim(mb_substr((string)$item['entity'], 0, 60)) : '';

                $line = '- ' . ($parent !== '' ? $parent . ' > ' : '') . $label;
                if ($href !== '' && $href !== '#') $line .= " -> `{$href}`";
                if ($entity !== '') $line .= " (entita' `{$entity}`)";
                $lines[] = $line;
                if (++$n >= self::MAX_MENU_ITEMS) break;
            }
            if (!count($lines)) return '';
            return "MENU DEL PROGETTO\nLe voci che l'utente vede nella barra laterale, con la rotta. Quando l'utente nomina una sezione con queste parole, e' qui che va portato.\n"
                . implode("\n", $lines);
        }

        private function project() {
            $file = __DIR__ . self::PROJECT_FILE;
            if (!file_exists($file)) return '';
            $text = @file_get_contents($file);
            if ($text === false) return '';
            $text = trim($text);
            if ($text === '') return '';
            if (strlen($text) > self::MAX_PROJECT_BYTES) $text = substr($text, 0, self::MAX_PROJECT_BYTES);
            return "MANUALE DEL PROGETTO\nScritto da chi amministra questo gestionale: flussi di lavoro, significato degli stati, regole. Ha la precedenza sul manuale generico.\n\n" . $text;
        }
    }
?>
