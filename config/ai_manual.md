# Manuale del gestionale per l'assistente AI

Questo file lo legge l'assistente AI (`api/classes/ai/AiManual.php`) e lo
aggiunge al proprio manuale, dopo la parte generica del framework. E'
markdown libero, DEL PROGETTO: l'allineamento del framework non lo tocca.
Serve a spiegare al modello come lavora l'azienda dentro il gestionale:
flussi, significato degli stati, quale pagina usare per cosa. Frasi corte e
concrete: e' un prompt, non una guida. Le regole secche (definizioni di
fatturato, quale stato vale "cliente") vanno in `ai_assistant.prompt_notes`
di `config/settings.json`, che l'assistente applica anche ai widget.

Sostituire tutto quello che segue con il contenuto del progetto. Sezioni
consigliate:

## Cosa gestisce

Una riga sull'azienda e su cosa contiene il gestionale.

## Flussi di lavoro

Per ogni flusso principale, le entita' nell'ordine in cui si percorrono, con
il nome tecnico (`tabella`) e la voce di menu. Per ogni stato, cosa vuol dire.

## Pagine custom

Per ogni `/page/<Nome>`: a cosa serve e con quali parametri si apre.

## Livelli utente

Chi vede cosa, in una riga per livello.

## Definizioni ufficiali

I numeri che devono coincidere con le pagine di analisi, e da quale tabella e
campo nascono.
