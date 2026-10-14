# CKD Framework - Backend

Backend PHP basato sul **CKD Framework** — un framework configuration-driven che genera applicazioni CRUD complete da configurazione JSON.

## Tech Stack

- PHP (native)
- MySQL (PDO)
- PHPMailer (SMTP)

## Struttura

```
backend/
├── config/                 # DEL PROGETTO — mai toccata dall'allineamento
│   ├── settings.json       # Configurazione sistema (DB, mail, host)
│   └── tables.json         # Definizioni entità (file principale)
├── custom/                 # DEL PROGETTO — mai toccata dall'allineamento
│   ├── calls.php           # Registrazione endpoint custom autenticati
│   ├── public_calls.php    # Registrazione endpoint pubblici
│   ├── classes/            # Classi delle API custom autenticate
│   └── public_classes/     # Classi delle API pubbliche (no auth)
├── api/                    # DEL FRAMEWORK — sostituita in blocco
│   ├── classes/            # Classi core del framework (ai/ = assistente AI)
│   └── libs/               # Librerie di terze parti
├── docs/                   # DEL FRAMEWORK — sostituita in blocco
│   └── CLAUDE.md           # Documentazione completa del framework
└── utils/                  # DEL FRAMEWORK — sostituita in blocco
    └── db/                 # Utility creazione/aggiornamento DB
```

## Versione

`version.json` contiene `MAJOR.MINOR.PATCH`. Le prime due cifre sono del
framework e cambiano solo con un allineamento (`3.0.x` → `3.1.0`). L'ultima è
del progetto: chi modifica `custom/` o `config/` alza solo quella
(`3.1.0` → `3.1.1`). L'allineamento sovrascrive il file, quindi a ogni
release del framework il contatore di progetto riparte da `.0`.

Ogni cartella di primo livello ha un solo padrone. `api/`, `utils/` e `docs/`
vengono cancellate e ricopiate dal template a ogni allineamento: il codice del
progetto va in `custom/`, la configurazione in `config/`.

## Documentazione

La documentazione completa del framework si trova in [docs/CLAUDE.md](docs/CLAUDE.md).
