# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**CKD Framework** is a configuration-driven full-stack framework that generates complete CRUD applications from JSON configuration files. The system consists of:
- **Backend**: PHP + MySQL (PDO) with auto-generated REST APIs
- **Frontend**: React SPA with auto-generated UI components
- **Philosophy**: Configure entities in JSON instead of writing repetitive CRUD code

The framework automatically generates:
- MySQL tables/views from entity definitions
- Complete REST API endpoints for all entities
- React UI components with full CRUD operations
- Permission-based access control
- Search and filtering capabilities

---

## Critical Rules

### Files You Can Modify

**ONLY modify these locations:**
- `/config/` - Backend configuration (settings.json, tables.json)
- `/custom/classes/` - Custom authenticated API classes
- `/custom/public_classes/` - Public API classes (no auth required)
- `/custom/calls.php` - Register custom API endpoints
- `/custom/public_calls.php` - Register public API endpoints

`/config/` and `/custom/` are the **only** two directories owned by the project.
Everything else — `/api/`, `/utils/`, `/docs/` — belongs to the framework and is
**deleted and replaced wholesale** on every alignment. A change made there is
not "overwritten later", it is destroyed at the next alignment.

Inside `/custom/`, always require with `__DIR__`:

```php
require __DIR__ . '/classes/YourClass.php';
```

A plain `require 'classes/YourClass.php'` resolves against the calling script's
directory and the cwd, which is `/api/` — not `/custom/` — so it breaks in ways
that are hard to spot.

### `custom/override/` - Overriding standard classes (last resort)

If `custom/override/<Name>.php` exists, the framework loads it **instead of**
`api/classes/<Name>.php` (via `ckd_require_class()` in `api/functions.php`).

**This is an edge case — avoid it whenever possible.** Project logic belongs in
`custom/classes/`. An override replaces the standard class wholesale and must be
manually re-aligned every time the framework updates that class: whoever adds an
override owns that diff forever. Legitimate use: a project that must keep an
older behavior of a core class for external compatibility (e.g. a legacy client
still sending the old constraints format to `Get`).

Inside an override, reach standard classes with an absolute path
(`dirname(__DIR__, 2) . '/api/classes/Name.php'`), not `__DIR__`-relative ones.

### Files You MUST NOT Modify

**DO NOT modify anything in:**
- `/api/classes/` - Core framework classes (Auth, Get, Post, Delete, Resource, etc.)
- Any file outside the locations listed above

**IMPORTANT**: This is a strict framework rule. Modifying core files will break the framework and prevent updates.

### Database Connection - PDO Only

**The framework uses PDO (PHP Data Objects), NOT MySQLi or MysqliDb:**

```php
// CORRECT - PDO methods
$stmt = $this->db->prepare("SELECT * FROM table WHERE id = :id");
$stmt->bindParam(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// WRONG - These are MySQLi/MysqliDb methods, do not use
$this->db->where('id', $id);           // MysqliDb method
$this->db->getValue('table', 'field'); // MysqliDb method
$result->fetch_assoc();                // MySQLi method
$stmt->get_result();                   // MySQLi method
$stmt->bind_param();                   // MySQLi method
```

**Common PDO patterns:**
```php
// Single result
$stmt = $this->db->prepare("SELECT COUNT(*) as total FROM table WHERE deleted = 0");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total = $result['total'];

// Multiple results
$stmt = $this->db->prepare("SELECT * FROM table WHERE deleted = 0");
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Process each row
}

// With named parameters
$stmt = $this->db->prepare("SELECT * FROM table WHERE id = :id AND deleted = 0");
$stmt->bindParam(':id', $itemId, PDO::PARAM_INT);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
```

---

## Configuration Files

### `/config/settings.json`

System configuration including:
- **db**: Database connection (server, name, username, password, timezone)
- **mail**: SMTP email configuration (server, auth, username, password, secure type, port)
- **host**: API domain URL
- **not_loged**: Demo user for unauthenticated calls (with `calls_allowed` flag)
- **login_social**: Social login credentials (Google client ID, Facebook app ID and secret)
- **file**: Allowed file types and max upload size in bytes
- **storage**: Storage backend for physical files (`local` by default, or `supabase`). See [Storage Backends](#storage-backends-storagemanager).

**Full example:**
```json
{
    "db": {
        "server": "localhost",
        "name": "dbname",
        "username": "root",
        "password": "password_db",
        "timezone": "Europe/Rome"
    },
    "mail": {
        "smtp_server": "smtp.eu.mailgun.org",
        "smtp_enable_auth": true,
        "smtp_username": "smtp@yec-playground.it",
        "smtp_password": "password_casella_mail",
        "smtp_secure_type": "ssl",
        "smtp_port": 465
    },
    "host": {
        "domain": "localhost/ckd"
    },
    "not_loged": {
        "calls_allowed": false,
        "username": "not-logged",
        "name": "user",
        "surname": "user",
        "idUserGroup": "0",
        "level": 999,
        "id": "0"
    },
    "login_social": {
        "google_client_id": "",
        "fb_app_id": "",
        "fb_secret": ""
    },
    "file": {
        "allowed_types": "pdf, jpg, jpeg, png, doc, docx",
        "max_size": 5242880
    },
    "storage": {
        "provider": "local",
        "supabase": {
            "url": "https://<ref>.supabase.co",
            "bucket": "tie-uploads",
            "service_key": "<service_role_key>",
            "public": true
        }
    }
}
```

**Key fields explained:**
- `not_loged.calls_allowed`: When `false`, unauthenticated CRUD calls are rejected. When `true`, they use the demo user identity.
- `not_loged.level`: Set to 999 (or any high number) so the demo user has minimal permissions.
- `login_social`: Required for Google/Facebook social login. Leave empty strings to disable.
- `file.max_size`: Maximum upload size in bytes (5242880 = 5MB).
- `storage.provider`: `local` (default) stores files on disk under `/upload/`; `supabase` stores them in a Supabase Storage bucket. If the `storage` block is missing, `local` is assumed (fully backward compatible). See [Storage Backends](#storage-backends-storagemanager).

### `/config/tables.json` - The Core File

This is the **most important file** in the framework. Every entity definition here automatically generates:
- MySQL table/view (or uses existing table/view)
- Complete REST API endpoints
- React UI components with CRUD operations
- Permission-based access control

**Required base fields** (always present in every entity):
```json
{
  "id": {
    "type": "INT",
    "length": "11",
    "input_type": "number",
    "null": false,
    "options": {"visible": false, "editable": false}
  },
  "date": {
    "type": "TIMESTAMP",
    "length": "30",
    "input_type": "datetime",
    "null": false,
    "default": "CURRENT_TIMESTAMP",
    "options": {"visible": false, "editable": false}
  },
  "lastUpdate": {
    "type": "TIMESTAMP",
    "length": "30",
    "input_type": "datetime",
    "null": false,
    "default": "CURRENT_TIMESTAMP",
    "on_update": "CURRENT_TIMESTAMP",
    "options": {"visible": false, "editable": false}
  },
  "deleted": {
    "type": "INT",
    "length": "2",
    "input_type": "boolean",
    "null": false,
    "options": {"visible": false, "editable": false}
  }
}
```

**Auto-populated fields** (optional, automatically set by framework):
- `author`: Automatically set to current user's ID on create
- `idUserGroup`: Automatically set to current user's group ID on create

**Entity structure template:**
```json
{
  "entity_name": {
    "primary_key": "id",
    "default_key": "name",
    "authorization": {
      "groupField": "idUserGroup",
      "userField": "author"
    },
    "columns": {
      // Field definitions (include all base fields + custom fields)
    },
    "permissions": {
      "0": {"view": true, "create": true, "edit": true, "delete": true},
      "1": {"view": "group", "create": true, "edit": "group", "delete": "group"},
      "2": {"view": "user", "create": false, "edit": false, "delete": false}
    }
  }
}
```

**Key properties:**
- `primary_key`: Always "id"
- `default_key`: Field used for display/linking (usually "name" or "username"). This is the field the frontend uses to represent the entity in links and dropdowns.
- `authorization`: Defines which fields filter records by user/group
  - `groupField`: Field name containing group ID (usually "idUserGroup")
  - `userField`: Field name containing user ID (usually "author" or "id")

---

## Permission System

### User Levels
- **Level 0**: Admin (full access to everything)
- **Level 1**: Standard user (group-restricted access)
- **Level 2+**: Limited user (view-only or custom restrictions)

### Permission Values
- `true`: Full access to all records
- `false`: No access
- `"group"`: Access only to records where `groupField` matches user's `idUserGroup`
- `"user"`: Access only to records where `userField` matches user's `id`

### Permission Operations
- `view`: List and view records
- `create`: Create new records
- `edit`: Modify existing records
- `delete`: Soft delete records (sets `deleted=1`)

### How Permissions Work with Authorization

The `permissions` object in each entity maps user levels to CRUD access. The `authorization` object tells the framework which fields to check when permission is `"group"` or `"user"`:

- If a level has `"view": "group"`, the framework filters records where the entity's `groupField` value matches the requesting user's `idUserGroup`.
- If a level has `"view": "user"`, the framework filters records where the entity's `userField` value matches the requesting user's `id`.
- Entities with empty `permissions: {}` (like `auth_token`, `auth_pin`) are system-only and not accessible via normal CRUD.

---

## Core Entities

Every CKD project includes these base entities:

| Entity | Description |
|--------|-------------|
| `user` | Users with authentication (username/email + password). Contains: name, surname, username, email, password, level, idUserGroup, avatar |
| `user_group` | User groups for permission management. Contains: name |
| `auth_token` | Session tokens (idUser, sid, ltsid). System-only, no CRUD permissions |
| `auth_pin` | Password recovery PINs (idUser, pin). System-only, no CRUD permissions |
| `resource` | File upload references (idUser, entity, filename, hash, extension). Hash-based file management |
| `user_entity_saved_filters` | Saved search filters per user (author, entity, name, notes, content as JSON code field) |
| `user_entity_preferences` | UI preferences per user per entity (author, entity, label, isDefault, content as JSON code field) |
| `log` | System activity log (idUser, entity, idItem, operation, notes). Operations: 0_DELETE, 1_EDIT, 2_ADD, 3_LOGIN, 4_LOGOUT |

### User Entity Details

The `user` entity has these notable fields:
- `username`: VARCHAR(100), unique, nullable
- `email`: VARCHAR(100), unique, required
- `password`: VARCHAR(100), input_type "password", `options.private: true` (never returned in API responses)
- `level`: INT, input_type "option" with predefined levels (0=admin, 1=user, etc.), default value set in `options.default`
- `idUserGroup`: externalEntity linking to `user_group`
- `avatar`: option field with numeric values

### Log Entity Operations

The log tracks these operations as option values with a color variant each:
- `0_DELETE` (danger/red)
- `1_EDIT` (secondary/gray)
- `2_ADD` (success/green)
- `3_LOGIN` (info/blue)
- `4_LOGOUT` (info/blue)

---

## Field Types Reference

### Field Definition Structure

Every field in `columns` follows this structure:
```json
"field_name": {
    "type": "VARCHAR",
    "length": "100",
    "input_type": "string",
    "null": true,
    "unique": false,
    "default": null,
    "on_update": null,
    "options": {
        "visible": true,
        "editable": true,
        "private": false
    },
    "validation": {
        "required": false,
        "min": null,
        "max": null
    }
}
```

**Key field properties:**
- `type`: Database column type (VARCHAR, INT, FLOAT, TEXT, DATE, DATETIME, TIMESTAMP, NONE)
- `length`: Database column length. For TEXT type, use `null`.
- `input_type`: Frontend rendering type (determines the UI component)
- `null`: Whether the column is nullable in the database. Best practice: always nullable except for id, date, lastUpdate, deleted.
- `unique`: When `true`, the database enforces uniqueness on this column (`false` or absent: no constraint)
- `default`: Default value for the column, applied by `createdb`/`updatedb` as a SQL `DEFAULT` (e.g., "CURRENT_TIMESTAMP", a number, a string). Not applied to TEXT/BLOB columns
- `on_update`: Value set on update (e.g., "CURRENT_TIMESTAMP" for lastUpdate)
- `options.visible`: Whether the field appears in the datagrid/list view
- `options.editable`: Whether the field can be modified in the frontend form
- `options.private`: When `true`, the field value is never returned in API responses (used for passwords)
- `validation.required`: Whether the field is required in frontend forms
- `validation.min` / `validation.max`: Minimum/maximum value for number, float, and currency fields

### Basic Field Types

| input_type | Database Type | Description |
|------------|---------------|-------------|
| `string` | VARCHAR | Short to medium text (names, titles) |
| `text` | TEXT | Long text (descriptions, notes). When type is TEXT, set `length` to `null` |
| `number` | INT | Integer values. Supports `min`/`max` in validation |
| `float` | FLOAT | Decimal numbers. Supports `min`/`max` in validation |
| `currency` | FLOAT | Money values. Supports `min`/`max` in validation |
| `boolean` | INT | True/false (1/0) checkbox |
| `date` | DATE | Date only (YYYY-MM-DD) |
| `time` | TIME | Time only (HH:MM:SS) |
| `datetime` | DATETIME | Date + time. **Note:** Prefer using separate `date` + `time` fields for better cross-browser compatibility |
| `password` | VARCHAR | Auto-hashed, never displayed. Set `options.private: true` |
| `email` | VARCHAR | Email address |
| `phone` | VARCHAR | Phone number |
| `color` | VARCHAR | Color picker. Stores RGB or hex string |
| `code` | TEXT | Code editor with syntax highlighting. Set `options.language` to specify syntax (e.g., "json") |
| `richText` | TEXT | TinyMCE HTML editor. Stores HTML |
| `markdown` | TEXT | Plain-text editor with toolbar and preview. Stores Markdown, byte for byte as typed. Set `options.rows` for height (default 18) |
| `file` | INT | File upload. Stores hash reference to uploaded file |

### Advanced Field Types

#### `option` - Dropdown with predefined values
```json
{
  "status": {
    "type": "VARCHAR",
    "length": "30",
    "input_type": "option",
    "null": false,
    "options": {
      "visible": true,
      "editable": true,
      "options": [
        {"value": "active", "bg": "success"},
        {"value": "inactive", "bg": "danger"},
        {"value": "pending", "bg": "warning"}
      ],
      "default": "active"
    },
    "validation": {"required": true}
  }
}
```
- `value`: Value stored in database
- `name`: Optional display label (if omitted, `value` is displayed)
- `bg`: badge color variant (primary, secondary, success, danger, warning, info) — historical Bootstrap names, rendered by the `Badge` component
- `options.default`: Default selected value

For numeric option fields (like user level):
```json
{
  "level": {
    "type": "INT",
    "length": "3",
    "input_type": "option",
    "null": false,
    "default": "2",
    "options": {
      "visible": false,
      "editable": true,
      "options": [
        {"name": "admin", "value": 0, "bg": "primary"},
        {"name": "user", "value": 1, "bg": "secondary"}
      ],
      "default": 2
    },
    "validation": {"required": true}
  }
}
```

#### `file` - File upload
- Stores hash reference to uploaded file
- Physical files stored in `/upload/` directory
- Managed through `resource` entity

#### `address` - Google Maps address with autocomplete
```json
{
  "address": {
    "type": "VARCHAR",
    "length": "100",
    "input_type": "address",
    "null": true,
    "options": {
      "visible": true,
      "editable": true,
      "autocompile": {
        "latitude": "lat",
        "longitude": "lng",
        "route": null,
        "locality": "city",
        "country": "country",
        "postalCode": "cap",
        "streetNumber": null
      }
    }
  }
}
```
- `autocompile`: Maps Google Places API fields to entity fields. Each key is a Google Places component; its value is the field name in the entity to auto-fill, or `null` to skip.
  - `latitude` / `longitude`: Coordinate fields
  - `route`: Street name
  - `locality`: City
  - `country`: Country
  - `postalCode`: Postal/ZIP code
  - `streetNumber`: Street number

#### `markdown` - Plain text with Markdown

```json
{
  "instructions": {
    "type": "TEXT",
    "length": null,
    "input_type": "markdown",
    "null": true,
    "options": {
      "visible": false,
      "editable": true,
      "rows": 24
    }
  }
}
```
- `options.rows`: textarea height in rows (default 18)

Choose `markdown` over `richText` when the stored value is consumed by something
other than a browser — a language model, an export, an API. `richText` stores
HTML, which has to be converted before such use, and the conversion flattens
emphasis and inline-code markers: the author stops seeing what the consumer
receives. With `markdown` the stored bytes are exactly what was typed.

Both use a `TEXT` column, so switching between them requires no database change:
only `input_type` differs.

#### `code` - Code editor
```json
{
  "content": {
    "type": "TEXT",
    "length": null,
    "input_type": "code",
    "null": true,
    "options": {
      "visible": false,
      "editable": true,
      "language": "json"
    }
  }
}
```
- `options.language`: Syntax highlighting language (e.g., "json", "javascript", "php")

### Relationship Field Types

#### `externalEntity` - Foreign Key (Many-to-One)
```json
{
  "idUser": {
    "type": "INT",
    "length": "11",
    "input_type": "externalEntity",
    "null": false,
    "options": {
      "visible": true,
      "editable": true,
      "entity": "user",
      "labelField": "username",
      "visibleColumns": ["username", "email"]
    },
    "validation": {"required": true}
  }
}
```
- `entity`: The name of the related entity (e.g., "user" for a user foreign key)
- `labelField`: The field from the related entity to display as label (e.g., "username", "name")
- `visibleColumns` (optional): columns of the related entity shown in the picker modal for THIS
  specific relation, replacing the related entity's standard visible columns. Columns that don't
  exist in the related entity are ignored. Also supported on `multipleExternalEntity`.

#### `multipleExternalEntity` - Many-to-Many
Links to multiple records via a junction table. **Type is `NONE` (no database column created for this field).**

Used when you need to associate multiple IDs from another entity (e.g., which categories an article belongs to). Similar to `externalEntity` but supports multiple selections and requires a junction table.

```json
{
  "categories": {
    "type": "NONE",
    "length": "0",
    "input_type": "multipleExternalEntity",
    "null": true,
    "options": {
      "visible": true,
      "editable": true,
      "entity": "category",
      "labelField": "name",
      "link_entity": "article_categories",
      "source_field": "idArticle",
      "destination_field": "idCategory"
    }
  }
}
```
- `entity`: **Target** entity to link to (the entity being selected)
- `link_entity`: **Junction** table name (where associations are stored)
- `source_field`: Field in junction table referencing the parent entity's ID
- `destination_field`: Field in junction table referencing the linked entity's ID
- `labelField`: Display field from the linked entity
- `visibleColumns` (optional): columns of the target entity shown in the picker modal for this
  specific relation (see `externalEntity`)

⚠️ Do not swap `entity` and `link_entity`: `entity` is the target, `link_entity` is the
junction table. `Get.php` builds the query as

```sql
SELECT * FROM {link_entity} l LEFT JOIN {entity} e ON l.{destination_field} = e.id
WHERE l.{source_field} = :id AND l.deleted = 0
```

and `Post.php` writes the associations into `{link_entity}`. Swapping them raises
`Unknown column 'l.{source_field}' in 'where clause'` at read time, and the
`tables.json` validator does **not** catch it.

The GET class automatically performs the join with the junction table to resolve these relationships.

#### `deepExternalEntity` - One-to-Many (Nested Records)
Embeds full child records. **Type is `NONE` (no database column created for this field).**

Unlike `multipleExternalEntity` (which only links IDs), `deepExternalEntity` stores complete child records with all their own fields. Used for nested data like comments on an article, line items on an order, etc.

```json
{
  "items": {
    "type": "NONE",
    "length": "0",
    "input_type": "deepExternalEntity",
    "null": true,
    "options": {
      "visible": true,
      "editable": true,
      "entity": "room_items",
      "labelField": "name",
      "source_field": "idRoom"
    }
  }
}
```
- `entity`: Child entity name (the entity storing the nested records)
- `source_field`: Field in the child entity that references the parent's ID
- `labelField`: Display field from the child entity
- `visibleColumns` (optional): columns of the child entity shown in the nested-records table for
  this specific relation, replacing the child entity's standard visible columns (e.g.
  `["date", "title"]`). Columns that don't exist in the child entity are ignored.

**IMPORTANT**: The parent reference field in the child entity must use `input_type: "number"`, NOT `externalEntity`:

```json
// In child entity (room_items)
"idRoom": {
  "type": "INT",
  "length": "11",
  "input_type": "number",  // Use number, not externalEntity
  "null": false,
  "options": {
    "visible": false,
    "editable": false
  }
}
```

The GET class automatically performs the join with the child entity to embed the nested records.

---

## Backend Structure

```
backend/
├── config/                  # PROJECT — never touched by alignment
│   ├── settings.json        # System configuration (DB, mail, host, social login, file upload)
│   └── tables.json          # Entity definitions (the core config file)
├── custom/                  # PROJECT — never touched by alignment
│   ├── .htaccess            # Denies HTTP access to the whole directory
│   ├── calls.php            # Register custom endpoints
│   ├── public_calls.php     # Register public endpoints
│   ├── classes/             # Custom authenticated API classes
│   └── public_classes/      # Public API classes
├── api/                     # FRAMEWORK — replaced wholesale, DO NOT MODIFY
│   ├── .htaccess            # URL routing rules
│   ├── dispatcher.php       # Authenticated CRUD router
│   ├── custom_calls.php     # Custom API router -> includes /custom/calls.php
│   ├── public_calls.php     # Public API router -> includes /custom/public_calls.php
│   ├── user.php             # Auth endpoints (login, logout, forgot, reset)
│   ├── file.php             # File upload/download
│   ├── profile.php          # User profile
│   ├── analytics.php        # Analytics
│   ├── classes/             # Core framework classes
│   └── libs/                # Third-party libraries
├── docs/                    # FRAMEWORK — replaced wholesale
│   ├── api.php              # API reference page
│   └── CLAUDE.md            # This documentation file
├── utils/                   # FRAMEWORK — replaced wholesale
│   └── db/                  # DB creation/update utilities
├── upload/                  # Physical file storage directory
├── index.php                # PROJECT
├── readme.md                # FRAMEWORK
└── version.json             # FRAMEWORK — which framework version this project is on
```

`/api/`, `/utils/` and `/docs/` are deleted and re-copied from the template at
every alignment. Never put project code there: put it in `/custom/`.

### Version numbers

`version.json` holds a three-part number, `MAJOR.MINOR.PATCH`, and the parts
have two different owners:

- **`MAJOR.MINOR` belong to the framework.** Every release of the template
  bumps one of them (`3.0.x` → `3.1.0`) and the alignment copies the file to
  the project.
- **`PATCH` belongs to the project.** Developers who change `custom/` or
  `config/` bump only the last part (`3.1.0` → `3.1.1` → `3.1.2`) to mark
  their own releases. The framework never uses it.

An alignment overwrites `version.json`, so the project counter restarts from
`.0` at every framework release: `3.1.7` becomes `3.2.0`, and the next project
release is `3.2.1`. The first two parts always tell which framework the project
is on, whatever the third says.

### .htaccess Routing

The `/api/.htaccess` file routes all API requests. Standard routes:

```apache
RewriteRule ^auth/(.*)$              user.php?action=$1 [NC,L,QSA]
RewriteRule ^get_items/(.*)$         dispatcher.php?action=get_items&entity=$1 [NC,L,QSA]
RewriteRule ^get_item/(.*)$          dispatcher.php?action=get_item&entity=$1 [NC,L,QSA]
RewriteRule ^search_items/(.*)$      dispatcher.php?action=search_items&entity=$1 [NC,L,QSA]
RewriteRule ^post/(.*)$              dispatcher.php?action=post&entity=$1 [NC,L,QSA]
RewriteRule ^delete/(.*)$            dispatcher.php?action=delete&entity=$1 [NC,L,QSA]
RewriteRule ^custom/(.*)$            custom_calls.php?action=$1 [NC,L,QSA]
RewriteRule ^public/(.*)$            public_calls.php?action=$1 [NC,L,QSA]
RewriteRule ^resource/(.*)$          file.php?action=$1 [NC,L,QSA]
RewriteRule ^users/(.*)$             users_functions.php?action=$1 [NC,L,QSA]
RewriteRule ^profile/(.*)$           profile.php?action=$1 [NC,L,QSA]
RewriteRule ^analytics/(.*)$         analytics.php?action=$1 [NC,L,QSA]
```

Custom standalone endpoints can be added by creating new rewrite rules pointing to new PHP files.

---

## Maintenance Tools (`utils/`)

Web pages served from the `utils/` directory, used to bootstrap and inspect a
project. Entry point: **`<domain>/utils/`**.

| Page | What it does |
|---|---|
| `utils/index.php` | Landing page with the three tools and the database it is pointing at |
| `utils/tablesJsonValidation/validate.php` | Validates `config/tables.json`: structure, permissions, orphan entities, and the `entity_language` strings still to translate |
| `utils/db/updatedb.php` | **Non-destructive.** Creates missing tables and adds new columns from `tables.json`. Never alters or drops existing columns |
| `utils/db/createdb.php` | **Destructive.** Drops and recreates every table. Requires the database password |
| `utils/_ui.php` | Shared helpers: `ckd_column()` reads a field definition, `ckd_column_definition()` turns it into the SQL column definition (type, length, charset, NULL, UNIQUE, DEFAULT) used by both tools |

**`utils/` is protected by its own `.htaccess`**: it exposes a tool that can
wipe the database, so it is reachable over HTTP **only from the IPs
whitelisted there** (`Require ip` on Apache 2.4, `Allow from` on 2.2) and
denied to everyone else. The list lives in the template: to add or change an
IP, edit it there and re-align — `utils/` is framework-owned, so a change made
in a project is lost at the next alignment.

`createdb.php` asks for `db.password` from `settings.json` through a POST form,
plus an explicit confirmation. If that password is empty the page refuses to run
at all: there would be no secret with which to authorise wiping the data. It
also inserts a sample group and user (`marco.yec`) so the backoffice is usable
immediately.

Entities whose name starts with `gyv_` are treated as **views**: neither
`createdb` nor `updatedb` create or alter them.

Both tools build each column from the same definition: `type(length)`,
charset for text types, `NULL`/`NOT NULL`, `UNIQUE` only when `unique` is
truthy (`true`, `1`, `"true"`; `"unique": false` does nothing), and `DEFAULT`
from the `default` key. Keywords such as `CURRENT_TIMESTAMP` or `NOW()` are
emitted unquoted, numbers and booleans as literals (`false` becomes `0`), any
other string is quoted. `TEXT`/`BLOB`/`JSON` columns never get a default since
MySQL rejects it. If the database refuses a default (a typo like
`CURRENT_DATETIME`, an expression, an empty string on an `INT`), the column is
created again **without** it and listed under "Default non applicati" in the
summary: fix the value in `tables.json`, the column itself is fine.

## Backend API System

### Standard CRUD Endpoints (Auto-Generated)

All entities automatically have these endpoints:

| Method | Endpoint | Purpose | Body Parameters |
|--------|----------|---------|-----------------|
| POST | `/get_items/{entity}` | List items with pagination | `{page, limit, order, offset}` |
| POST | `/get_item/{entity}` | Get single item by ID | `{id}` |
| POST | `/post/{entity}` | Create item | `{action: 'create', body: data}` |
| POST | `/post/{entity}` | Update item | `{action: 'edit', id: id, body: data}` |
| POST | `/delete/{entity}` | Soft delete item | `{id}` |
| POST | `/search_items/{entity}` | Advanced search | `{query, fields, constraints, page, limit, order}` |
| POST | `/get_items_simple/{entity}` | List items, no nested data | same as `/get_items/` |
| POST | `/get_item_simple/{entity}` | Get single item, no nested data | same as `/get_item/` |
| POST | `/search_items_simple/{entity}` | Search, no nested data | same as `/search_items/` |

**The `order` parameter** is a string placed after `ORDER BY`, and supports
multiple columns: `"id DESC"` or `"id DESC, date ASC"`. Since 3.0.5 it is
validated by `ckd_sanitize_order()` (api/functions.php): each comma-separated
segment must be `field_name [ASC|DESC]` — anything else invalidates the whole
string and the default (`id DESC`) is used. `RAND()` stays supported on the
search endpoints. In the search queries (aliased `e.`) the prefix is applied
to every field of the list.

**The `_simple` variants** are handled by `GetSimple` instead of `Get`. The only
difference: `parseData` stops at the first level and does not recursively resolve
child entities. Same request and response shape, minus the nested objects —
noticeably faster on large datasets, so use them whenever the related entities
are not needed by the caller.

**Create/Update body data**: The `body` (pData) should contain all entity fields EXCEPT: `id`, `date`, `lastUpdate`, `deleted`, `author`, `idUserGroup` (these are auto-managed by the framework).

**Request format:**
- Method: Always `POST`
- Headers (authenticated): `{'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ${sid}'}`
- Headers (public): `{'Content-Type': 'application/json', 'Accept': 'application/json'}`

**Response format:**
```json
{
  "success": 1,
  "body": {},
  "message": "Success message",
  "error_code": "not.logged | refresh.sid"
}
```

**Error codes:**
- `not.logged`: User not authenticated. Must login.
- `auth.refresh.sid` / `refresh.sid`: Session expired, send a refresh SID request.
- `user.disabled`: Authenticated request by a disabled user.
- `login.disabled`: Disabled user attempts login.
- `forgot.disabled`: Disabled user requests password reset.
- `checkLogin.disabled`: Session belongs to a disabled user.
- `refreshSid.disabled`: Token refresh for a disabled user.
- `db.error`: Database error.
- `api.*.not.found`: Requested API not found.
- `api.*.forbidden`: Access denied.

### Search Constraints

`constraints` is an **array of objects**, each with `fieldName`, `type` and a
value. `Get.php` iterates it in order and joins the conditions with `AND`.
This is the same shape the frontend `DataManager.searchItems` sends.

```json
{
  "query": "search text",
  "fields": ["name", "email"],
  "constraints": [
    { "fieldName": "name",     "type": "value",                "value": "John" },
    { "fieldName": "age",      "type": "greater",              "value": 18 },
    { "fieldName": "price",    "type": "minor",                "value": 100 },
    { "fieldName": "status",   "type": "different_from_value", "value": "deleted" },
    { "fieldName": "title",    "type": "like",                 "value": "hello" },
    { "fieldName": "code",     "type": "start",                "value": "PRE" },
    { "fieldName": "email",    "type": "end",                  "value": "@gmail.com" },
    { "fieldName": "date",     "type": "range",                "min": "2024-01-01", "max": "2024-12-31", "range_type": "STR" },
    { "fieldName": "category", "type": "in",                   "value": "1,2,3" },
    { "fieldName": "notes",    "type": "isnull" }
  ]
}
```

**Constraint types:**
| Type | Description | SQL Equivalent |
|------|-------------|---------------|
| `value` | Equal to | `= value` |
| `different_from_value` | Not equal to | `!= value` |
| `like` | Contains | `LIKE %value%` |
| `start` | Starts with | `LIKE value%` |
| `end` | Ends with | `LIKE %value` |
| `greater` | Greater than | `> value` |
| `minor` | Less than | `< value` |
| `range` | Between `min` and `max` (no `value` key) | `BETWEEN min AND max` |
| `in` | In a comma-separated **string**, not an array | `IN ('a','b','c')` |
| `not_in` | Not in a comma-separated **string** | `NOT IN ('a','b','c')` |
| `in_ext` | Only on `multipleExternalEntity` fields: rows linked to any of the listed ids | `JOIN {link_entity} ... IN (ids)` |
| `isnull` | Is null | `IS NULL` |

Notes:
- `range` takes `min` and `max`, plus an optional `range_type` (`STR` or `INT`,
  default `INT`) that sets the PDO bind type. The frontend always sends `STR`,
  which is right for dates and numbers alike in MySQL comparisons.
- `in` / `not_in` values are interpolated after `explode(",")`, so pass ids or
  option values, never free text.
- Unknown types are silently skipped.

The `query` field performs a `LIKE %query%` search across all fields listed in the `fields` array.

### Authentication API

| Endpoint | Purpose | Body |
|----------|---------|------|
| `/auth/login` | Login | `{username, password, rememberme}` |
| `/auth/checkLogin` | Validate session | `{ltsid}` |
| `/auth/refreshSid` | Refresh token | `{ltsid}` |
| `/auth/logout` | Logout | `{ltsid}` |
| `/auth/forgot` | Password recovery | `{email}` |
| `/auth/reset` | Reset password | `{pin, newPassword, confirmPassword}` |

**Authentication response fields:**
- `sid`: Short-term session token (for Authorization header)
- `ltsid`: Long-term session token (for token refresh)
- `body`: User information JSON
- `auth_map`: Permission matrix for all entities (JSON of permissions by entity)
- `props`: Entity property definitions (JSON of column definitions)

**Password encryption:** SHA1

**Login/checkLogin/refreshSid all return the same response structure** with sid, ltsid, body, auth_map, and props.

### User Management API

| Endpoint | Purpose | Body |
|----------|---------|------|
| `/users/disable` | Disable user account | `{id}` |
| `/users/enable` | Enable user account | `{id}` |
| `/users/edit` | Edit user (without password). Accepts every editable `user` column, including `multipleExternalEntity` (link table) and `deepExternalEntity` (child rows, e.g. shifts) — send the child rows flattened with `DataManager.parseData` | `{id, username, email, ...}` |

**User Disable/Enable System:**
- The `disabled` field in the user table controls account status
- When disabled, all auth_tokens are deleted (forced logout everywhere)

### Own Account API (`/account/`)

Acts on the currently authenticated user, not on an arbitrary one. Handled by
`api/profile.php`.

| Endpoint | Purpose | Body |
|----------|---------|------|
| `/account/updateProfile` | Update own profile | `{name, surname, email, avatar, ...}` |
| `/account/updatePassword` | Change own password | `{oldPassword, newPassword, confirmPassword}` — SHA1 encrypted |

### External Services API (`/external_api/`)

Server-side proxy for third-party services, so API keys stay in
`config/settings.json` and never reach the browser. Handled by
`api/external_api.php`, routed by `api/external_api_config.php`.

| Endpoint | Purpose |
|----------|---------|
| `/external_api/get_google_maps_address` | Resolve an address via Google Maps |
| `/external_api/get_google_maps_suggestions` | Address autocomplete suggestions |

Requires `api_keys.google_maps_api_key` in `settings.json`.

### Framework Utility API (`/utils/`)

Powers the import/export and query features of the backoffice. Handled by
`api/utils.php`, routed by `api/utils_config.php`. Not to be confused with the
`utils/` **directory**, which holds the web maintenance tools — see
"Maintenance tools" below.

| Endpoint | Purpose |
|----------|---------|
| `/utils/import_parse_file` | Parse an uploaded file and preview the rows to import |
| `/utils/import_download_template` | Download the import template for an entity |
| `/utils/import_execute` | Run the import (`Import` class) |
| `/utils/export_for_import` | Export an entity in a format the importer accepts (`Export` class) |
| `/utils/getDependencyInfo` | Which records depend on a given one, before deleting it |
| `/utils/pullTablesStatus` | Compare `tables.json` with the actual database schema |
- Only users with edit permission on user entity can disable/enable
- The `disabled` field is optional -- if it doesn't exist, the system works normally

**Edit user allowed fields:** `name`, `surname`, `username`, `email`, `level`, `idRole`, `avatar`, `phone`, `notes`, `password` (only updated if provided and not empty), **plus every column of the `user` entity marked `editable: true` in `tables.json`** (since 3.0.9). `multipleExternalEntity` columns (e.g. a `companies` link table) are accepted as an array of ids or of objects with `id` and rewrite the link table with soft delete; `deepExternalEntity` columns are ignored. Values may be plain scalars or `{ id }` / `{ value }` objects.

**Frontend edit user call:**
```javascript
const response = await ApiManager.sendAuthenticatedRequest('/users/edit', {
    id: userId,
    username: 'newUsername',
    email: 'newemail@example.com',
    level: { value: 1 },         // Option field format
    idRole: { id: 2 }            // ExternalEntity field format
});
```

### Resource API (File Upload/Download)

| Endpoint | Purpose | Body |
|----------|---------|------|
| `/resource/uploadFile` | Upload file | `{entity, filename, filedata}` |
| `/resource/getFile` | Download file | `{entity, hash}` |

**Upload details:**
- `entity`: The entity this file belongs to (e.g., "resource")
- `filename`: Original filename with extension (e.g., "test.pdf")
- `filedata`: Base64-encoded with data URI prefix (e.g., `data:application/pdf;base64,JVBERi0xLjUK...`)
- Returns: `{success, hash, extension}`
- Physical bytes are written/read through **`StorageManager`** (provider `local` → `/upload/{entity}/`, or `supabase` → bucket). The `resource` DB table (metadata: `entity`, `hash`, `extension`, `filename`) is unchanged and provider-agnostic. See [Storage Backends](#storage-backends-storagemanager).
- Allowed types and max size are configured in `settings.json` under `file`

---

## Storage Backends (StorageManager)

Physical file bytes are handled by a single class, **`/api/classes/StorageManager.php`**, so the framework can store files either on the local disk or on an external provider without changing the callers. The `resource` DB table (metadata) is **never** touched by this — only *where the bytes live* changes.

### Configuration (`settings.json`)

```json
"storage": {
    "provider": "local",
    "supabase": {
        "url": "https://<ref>.supabase.co",
        "bucket": "tie-uploads",
        "service_key": "<service_role_key>",
        "public": true
    }
}
```

- `provider: "local"` (or the `storage` block missing) → files under `/upload/{entity}/{hash}.{ext}`. **Identical to the historical behavior — fully backward compatible.**
- `provider: "supabase"` → files in the Supabase Storage bucket, under the object key `upload/{entity}/{hash}.{ext}` (the `upload/` prefix mirrors the local layout and the frontend URL structure).
- `service_role` key is **backend-only** — never expose it to the frontend.

### API

```php
StorageManager::put($entity, $name, $content);   // write bytes (throws on error)
StorageManager::get($entity, $name);             // read bytes | false
StorageManager::exists($entity, $name);          // bool
StorageManager::size($entity, $name);            // int | null (null on supabase — no per-file HEAD)
StorageManager::url($entity, $name);             // public URL of the file
StorageManager::useProvider($p);                 // force provider at runtime (used only by the migration script)
```

`$entity` = logical subfolder (e.g. `resource`, `cargo_documents`); `$name` = `hash.ext`.

- **local** driver: replicates the historical logic (`mkdir` + `file_put_contents`/`file_get_contents`, `filesize`, URL = `{host.domain}/upload/...`).
- **supabase** driver: Supabase Storage REST API via cURL (no Composer dependency). Public bucket → `url()` returns `{url}/storage/v1/object/public/{bucket}/upload/{entity}/{name}`.

### Callers (where the abstraction is wired in)

- `Resource.php` → `fetchUpload` (write), `fetchGet` (read + `link`)
- `Get.php` / `GetSimple.php` → `size` for `input_type: "file"` fields
- `custom/classes/VoyageManager.php` → `cargo_documents` write/read (LDV generation, E-Port sync)

### Frontend counterpart

The React config `src/config/settings.js` exposes **`storageUrl`** (previously `ckdPath`), used to build direct file URLs (`storageUrl + "upload/" + entity + "/" + hash + "." + ext`). To switch the frontend to Supabase, set:

```js
"storageUrl": "https://<ref>.supabase.co/storage/v1/object/public/tie-uploads/"
```

Because the object key keeps the `upload/` prefix, backend `url()` and the frontend-built URL resolve to the same object.

### Switching to Supabase (checklist)

1. Create the **public** bucket (`tie-uploads`) in Supabase.
2. Fill `storage.supabase` in `settings.json`, keeping `provider: "local"`.
3. Migrate existing files (see `tie-migrations/` — Node script reading a `resource` dump, `local`/HTTP source → Supabase, with a resume ledger).
4. After verification: `provider` → `"supabase"` (backend) **and** `storageUrl` → Supabase base (frontend).

> **Notes**
> - `Resource.php` still resizes/recompresses JPGs (max 2000px, quality 60) *before* `put()` — this is provider-agnostic and unchanged. PNGs and `cargo_documents` are stored as-is.
> - `StorageManager` requires **no** DB schema change: a file's location is entirely determined by the active `provider`, so all files are expected to live on the currently configured provider (migrate everything before switching).

---

## Custom API Development

### Creating Custom Authenticated Endpoints

**1. Create custom class** in `/custom/classes/YourClass.php`:

```php
<?php
class YourCustomClass {
    protected $db;           // PDO database connection
    protected $user_info;    // Current user data (JSON string)
    protected $parameters;   // Request parameters (array)

    function __construct($db, $user_info, $parameters) {
        $this->db = $db;
        $this->user_info = json_decode($user_info, true);
        $this->parameters = $parameters;
    }

    public function yourMethod() {
        $userId = $this->user_info['id'];
        $userGroup = $this->user_info['idUserGroup'];
        $userLevel = $this->user_info['level'];

        $param1 = $this->parameters['param1'] ?? null;

        $stmt = $this->db->prepare("SELECT * FROM table WHERE id = :id AND deleted = 0");
        $stmt->bindParam(':id', $param1, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => 1,
            'data' => $data,
            'message' => 'Operation completed successfully'
        ];
    }
}
?>
```

**2. Register endpoint** in `/custom/calls.php`:

```php
<?php
require __DIR__ . '/classes/YourCustomClass.php';

if($action == "your_action_name") {
    $class = new YourCustomClass($conn, $user_info, $input_data);
    $body_response = $class->yourMethod();
}
else {
    $body_response['message'] = "Api (custom) not found. ({$action})";
    $body_response['success'] = 0;
    $body_response['status'] = 404;
}
?>
```

**IMPORTANT**: The action name is part of the URL path (`/custom/your_action_name`), NOT a body parameter.

**3. Call from frontend:**

```javascript
// CORRECT
const response = await ApiManager.sendAuthenticatedRequest('/custom/your_action_name', {
  param1: 'value1'
});

// WRONG (common mistake)
const response = await ApiManager.sendAuthenticatedRequest('custom', {
  action: 'your_action_name',
  param1: 'value1'
});
```

### Creating Public Endpoints (No Authentication)

Same process but:
1. Place class in `/custom/public_classes/YourPublicClass.php`
2. Register in `/custom/public_calls.php` (with `require __DIR__ . '/public_classes/...'`)
3. Constructor receives `$db` and `$parameters` only (no `$user_info`)
4. Call with `ApiManager.sendRequest('/public/action_name', data)`

```php
<?php
class YourPublicClass {
    protected $db;
    protected $parameters;

    function __construct($db, $parameters) {
        $this->db = $db;
        $this->parameters = $parameters;
    }

    public function publicMethod() {
        return ['success' => 1, 'data' => []];
    }
}
?>
```

### Standalone Endpoint Files (Advanced)

For features requiring dedicated routing (like user management), you can create standalone endpoint files:

1. **Create endpoint file** in `/backend/api/` (e.g., `users_functions.php`)
2. **Create class file** in `/backend/api/classes/` (e.g., `UsersFunctions.php`)
3. **Add route** in `/backend/api/.htaccess`:
   ```apache
   RewriteRule ^users/(.*)$ users_functions.php?action=$1 [NC,L,QSA]
   ```

The endpoint file handles authentication boilerplate (checking for `AUTH-REFRESH-SID`, `NONE`, `USER-DISABLED` states) then delegates to the class methods.

---

## Frontend Development

The frontend is the React repo of the same project (`<project>-react`), built
with **Vite** and styled with **Tailwind CSS v4** plus shadcn-style components
vendored in `src/components/ui/`. Bootstrap, react-bootstrap, react-toastify
and react-select are gone since core 3.0.0.

This section is a summary. The full frontend guide is the **`claude.md` in the
root of the React repo**: read it before writing any custom page.

### Directory Structure

```
index.html                 # PROJECT — only for <title> and favicon
vite.config.mjs            # FRAMEWORK — DO NOT MODIFY
.nvmrc                     # FRAMEWORK — Node 22
src/
├── App.js, index.js       # FRAMEWORK — DO NOT MODIFY
├── assets/                # PROJECT — images
├── components/            # FRAMEWORK — UI components; ui/ holds Button, Dialog, toast, …
├── core/                  # FRAMEWORK — data-manager, api-manager, resource-manager,
│                          #   login-manager, view-manager, authorization-manager,
│                          #   cache-manager, language-provider, utils
├── css/                   # FRAMEWORK — tailwind.css (design tokens), grid-compat.css, …
├── pages/                 # FRAMEWORK — system pages
└── config/                # PROJECT — the ONLY folder to modify
    ├── settings.js          # apiUrl, storageUrl, title, locale, modal sizes, level gates
    ├── menu.js              # Sidebar menu
    ├── entity_language.js   # Entity and field labels
    ├── Dashboard.js         # Component rendered at /
    ├── custom.css           # Project CSS and design-token overrides
    ├── logo.svg, login.jpg  # Brand assets referenced by settings.js
    ├── hooks/               # Business logic and UI injection hooks (see below)
    ├── pages/<Name>/index.js     # Custom authenticated pages → /page/<Name>
    └── nl_pages/<Name>/index.js  # Custom public pages → /nl_page/<Name>
```

`src/components/`, `src/core/`, `src/pages/` and `src/css/` are replaced
wholesale at every alignment, exactly like `api/` on the backend. Re-branding
is done by overriding the design tokens (`--primary`, `--sidebar`, …) from
`src/config/custom.css`, never by editing `src/css/tailwind.css`.

Custom pages live in `src/config/pages/<Name>/index.js` — the folder plus
`index.js` layout is required, since pages are resolved with `import.meta.glob`
and each becomes its own lazy chunk. JSX stays in `.js` files (no `.jsx`).

### Frontend Routing

```
/                          -> Dashboard (src/config/Dashboard.js)
/login, /recover, /reset   -> Authentication
/entity/:entity            -> Entity list (auto-generated CRUD)
/entity/:entity/add        -> Create new
/entity/:entity/edit?id=X  -> Edit
/entity/:entity/clone?id=X -> Clone
/entity/:entity/item?id=X  -> Detail view
/page/:page                -> Custom page (config/pages/)
/nl_page/:page             -> Public page (config/nl_pages/, no auth)
/settings                  -> Own profile and password
/users                     -> User administration
/resources                 -> File manager
/analytics                 -> Analytics dashboard (levels in settings.advancedAnalytics)
/schema                    -> Entity schema browser
/ai-assistant              -> AI assistant (levels in settings.aiAssistantLevels)
/logout
```

Entity list pages keep their state in a readable query string, useful for links
from custom pages and actions:

```
/entity/customer?query=rossi&page=2&limit=50&sort=name asc, id desc
/entity/customer?f=status:value:active&f=date:range:2024-01-01..2024-12-31
```

Each `f` is `field:type:value` using the constraint types above; `range` is
`field:range:min..max`, `isnull` has no value.

### Core Functions

#### DataManager - CRUD Operations

```javascript
import { DataManager } from '../../../core/data-manager';

const response = await DataManager.getItems('entity', 1, 20, {field: 'id', order: 'DESC'});
const item = await DataManager.getItem('entity', 123);
const newItem = await DataManager.insertItem('entity', {name: 'New Item'});
const updated = await DataManager.updateItem('entity', {name: 'Updated'}, 123);
const deleted = await DataManager.deleteItem('entity', 123);

// sorting also accepts an ARRAY for multi-column ordering:
// [{ field: 'id', order: 'DESC' }, { field: 'date', order: 'ASC' }]

// Advanced search: constraints is an ARRAY of objects (see "Search Constraints")
const results = await DataManager.searchItems(
  'entity', 1, 20,
  {field: 'name', order: 'ASC'},
  'search term',
  ['name', 'description'],
  [
    { fieldName: 'status', type: 'value', value: 'active' },
    { fieldName: 'price',  type: 'greater', value: 100 }
  ]
);

// *_Simple variants skip nested entities: same shape, much faster
const list = await DataManager.getItemsSimple('entity', 1, 100, {field: 'id', order: 'DESC'});
```

#### ApiManager - HTTP Client

```javascript
import { ApiManager } from '../../../core/api-manager';

// Authenticated request (adds Bearer token automatically)
const response = await ApiManager.sendAuthenticatedRequest('/custom/my_action', {param: 'value'});

// Public request (no authentication)
const publicResponse = await ApiManager.sendRequest('/public/action', {data: 'value'});
```

The action is part of the URL (`/custom/my_action`), never a body parameter.

#### ResourceManager - File Operations

```javascript
import { ResourceManager } from '../../../core/resource-manager';

const uploadResult = await ResourceManager.uploadBase64('data:image/png;base64,...', 'image.png', 'entity');
const fileUrl = ResourceManager.getFileUrl({hash: 'abc123', extension: 'jpg'}, 'entity');
const fileData = await ResourceManager.getFile('abc123', 'entity');
```

`getFileUrl` builds `settings.storageUrl + 'upload/' + entity + '/' + hash.ext`,
so `storageUrl` must point where the active storage provider serves files (see
[Storage Backends](#storage-backends-storagemanager)).

#### LoginManager - Current user

```javascript
import { LoginManager } from '../../../core/login-manager';

const userInfo = LoginManager.getUserInfo();   // id, level, idUserGroup, username, …
```

### Menu Configuration

**File:** `src/config/menu.js` — exports a flat `menu` array. Item types:
`header` (section title), `item`, `divider`, `folder` (with `items`).

```javascript
export const menu = [
  { type: 'header', href: '#', icon: '', entity: '', label: 'Pages' },
  { type: 'item', href: '/', icon: 'home', entity: '', label: 'Dashboard' },
  { type: 'item', href: '/entity/booking', icon: 'calendar', entity: 'booking', label: 'Bookings' },
  { type: 'item', href: '/page/MyPage', icon: 'chart-line', entity: '', label: 'My Page', hideForLevel: [1] },
  {
    type: 'folder', href: '#', icon: 'cogs', entity: '', label: 'Settings',
    items: [
      { type: 'item', href: '/users', entity: 'user', label: 'Users' },
      { type: 'item', href: '/entity/user_group', entity: 'user_group', label: 'Groups' }
    ]
  },
  { type: 'item', href: '/logout', icon: 'arrow-right-from-bracket', entity: '', label: 'Logout' }
];
```

- `entity`: the item is hidden when the user has no `view` permission on it
  (empty string = always visible, subject to `hideForLevel`).
- `icon`: a **FontAwesome 7** solid icon name (`house`, `gear`, `xmark`…),
  rendered through the framework `Icon` component.
- URL formats: `/entity/{entity}`, `/page/{PageName}` (CamelCase, from
  `config/pages/`), `/nl_page/{PageName}` (from `config/nl_pages/`).

### Language Configuration

**File:** `src/config/entity_language.js`

```javascript
export const entity_language = {
  entity: {
    user: 'Users',
    booking: 'Bookings'
  },
  labels: {
    "booking": {
      "id": 'ID',
      "guest_name": 'Guest Name',
      "status": 'Status',
      "status.options.confirmed": 'Confirmed',
      "status.options.pending": 'Pending'
    }
  }
};
```

- `entity`: entity names to display labels (page titles, menu)
- `labels`: field names per entity (forms, datagrid columns)
- `{field}.options.{value}`: translation of `option` values

### Custom Pages

**1. Create the component** in `src/config/pages/MyPage/index.js`:

```javascript
import React, { useState, useEffect } from 'react';
import { Card, CardBody } from '../../../components/ui/card';
import { Button } from '../../../components/ui/button';
import { toast } from '../../../components/ui/toast';
import Icon from '../../../components/BodyElements/Icon';
import { ApiManager } from '../../../core/api-manager';
import './style.css';

export default function MyPage() {
  const [data, setData] = useState(null);

  useEffect(() => { loadData(); }, []);

  const loadData = async () => {
    const response = await ApiManager.sendAuthenticatedRequest('/custom/my_action', {});
    if (response.success === 1) setData(response.data);
    else toast.error(response.message);
  };

  // Il core avvolge già la pagina con gli stessi margini delle pagine entity
  // (mt-4 mb-4 w-full px-3): niente padding né container qui, o i margini
  // non combaciano con il resto del pannello.
  return (
    <div className="my-page">
      <h2 className="text-xl font-semibold mb-4">My Custom Page</h2>
      <Card><CardBody>
        <Button variant="primary" onClick={loadData}><Icon name="rotate" className="me-2" />Reload</Button>
      </CardBody></Card>
    </div>
  );
}
```

**2. Add it to the menu**: `{ type: 'item', href: '/page/MyPage', icon: 'chart-line', entity: '', label: 'My Page' }`

**3. Styles**: Tailwind utilities in JSX; any extra CSS in `style.css`, always
scoped to the page container (`.my-page .card { }`, never `.card { }`).

**Public pages** follow the same pattern in `src/config/nl_pages/` and are
served at `/nl_page/PageName` without authentication.

Icons: always the `Icon` component (`<Icon name="trash" />`), never
`FontAwesomeIcon` directly. Modals: `Dialog*` from `ui/dialog`. Notifications:
`toast.success/error/loading/update` from `ui/toast`. Searchable selects:
`Combobox` / `MultiCombobox` from `ui/combobox`. The complete component table
is in the React repo's `claude.md`.

### Hooks System

Hooks live in `src/config/hooks/`. Two families.

**Data lifecycle hooks** run around every CRUD call made by the framework UI:

| Hook | Signature | Return |
|------|-----------|--------|
| `preInsert` | `(entity, data)` | `{success: 0|1, data, message}` |
| `onInsert` | `(response, entity, data)` | void |
| `preUpdate` | `(entity, data, id)` | `{success: 0|1, data, message}` |
| `onUpdate` | `(response, entity, data, id)` | void |
| `preDelete` | `(entity, id)` | `{success: 0|1, message}` |
| `onDelete` | `(response, entity, id)` | void |

```javascript
export async function preInsert(entity, data) {
  if (entity === 'booking' && data.capacity > 100) {
    return { success: 0, message: 'Capacity cannot exceed 100' };
  }
  return { success: 1, data: data };
}
```

`pre` hooks can block (`success: 0`) or rewrite `data`; `on` hooks trigger
side effects after the call.

**UI injection hooks** are React components rendered by the framework at fixed
points; each receives the `entity` and switches on it:

| Hook | Where | Props |
|------|-------|-------|
| `HeaderHook` | Header bar | — |
| `StandardViewTopHook` / `StandardViewBottomHook` | Above / below the entity list | `data, entity, searchObj, setSearchObj` |
| `DataGridTopHook` | Above the datagrid | `entity, data, setData, searchObj, setSearchObj, total, refreshData` |
| `DataGridTrHook` | Extra row after each `<tr>` | `item, props, entity, refreshData` |
| `DataGridLastTdHook` | Extra cell at the end of each row | `item, props, entity, data, setData, refreshData` |
| `DataGridSelectionHook` | Selection toolbar | `data, setData, entity, selectedRows, setSelectedRows, refreshData` |
| `InstanceCompilerTopHook` | Top of the add/edit form | `data, dataId, entity, setDataKeyValue` |
| `ModalPickerTopHook` | Top of the externalEntity picker | `entity, selected, searchObj, setSearchObj, data` |

**`actions.js`** adds entries to the row dropdown (`DataGridRow`) and to the
detail modal footer (`ModalDetailsFooter`):

```javascript
export const actions = {
  DataGridRow: {
    booking: [
      { label: 'Open detail', icon: 'up-right-from-square',
        func: (item, navigate, refreshData) => navigate('/page/BookingDetail?id=' + item.id) }
    ]
  },
  ModalDetailsFooter: {
    booking: [
      { label: 'Action', icon: 'bolt', func: (item, navigate) => { /* ... */ } }
    ]
  }
};
```

**Rule**: if an entity has custom `DataGridRow` actions, the dropdown shows
**only** those — the standard view/edit/clone/delete entries disappear. Add them
back explicitly: `navigate('/entity/<e>/item?id=' + item.id)`,
`'/entity/<e>/edit?id='`, `'/entity/<e>/clone?id='`.

**`customFilters.js`** exports named functions returning constraint arrays,
referenced from `tables.json` by `externalEntity` pickers:

```json
"options": {
  "entity": "category",
  "labelField": "title",
  "filters": [
    { "operation": "customFilter", "reference": "filter01" },
    { "operation": "equalToValue", "field": "status", "value": "active" },
    { "operation": "equalToParentField", "field": "idCompany", "value": "idCompany" }
  ],
  "orderBy": { "field": "title", "order": "asc" }
}
```

- `customFilter`: calls `customFilters[reference]()` and adds the returned constraints.
- `equalToValue`: `field = value` on the picked entity.
- `equalToParentField`: `field` equals the value of `value` in the record being
  edited (dot path allowed, e.g. `idCompany.id`).
- `orderBy`: default sorting of the picker list.

---

## Database Views

Views provide read-only computed data. Views must start with prefix `gyv_`.

Define in tables.json like regular entities but with `create/edit/delete = false`:
```json
{
  "gyv_room_statistics": {
    "primary_key": "id",
    "default_key": "room_name",
    "columns": { ... },
    "permissions": {
      "0": {"view": true, "create": false, "edit": false, "delete": false}
    }
  }
}
```

Create the view in MySQL:
```sql
CREATE VIEW gyv_room_statistics AS
SELECT r.id, r.name as room_name, COUNT(b.id) as total_bookings
FROM stanza r LEFT JOIN booking b ON b.idRoom = r.id
WHERE r.deleted = 0 GROUP BY r.id;
```

Views are read-only in the framework: the frontend will not show create/edit/delete buttons.

---

## Development Workflows

### Adding a New Entity

1. **Define entity** in `/config/tables.json` (include base fields + custom fields + permissions)
2. **Update the database** -- visit `/utils/` and run *Aggiorna il database*
   (`utils/db/updatedb.php`), which adds the new tables and columns without
   touching existing data. Never use *Ricrea il database* on a project with real
   data: it drops everything
3. **Add translations** in `/frontend/src/config/entity_language.js`
4. **Add menu item** in `/frontend/src/config/menu.js` (URL: `/entity/entity_name`)
5. **Test** -- full CRUD is available immediately, no additional code needed
6. **Add custom logic** (optional) via hooks and custom API endpoints

### Creating a Custom API Endpoint

1. Create PHP class in `/custom/classes/` or `/custom/public_classes/`
2. Implement business logic using PDO for database queries
3. Register endpoint in `/custom/calls.php` or `/custom/public_calls.php`
4. Call from frontend using ApiManager with correct endpoint format

---

## Core Framework Classes

Located in `/api/classes/` (DO NOT MODIFY):
- `Auth.php` - Authentication system (handles disabled user checks)
- `Database.php` - Database connection (PDO)
- `Get.php` - GET operations with joins and permissions (handles externalEntity, multipleExternalEntity, deepExternalEntity joins)
- `GetSimple.php` - Simple GET without joins
- `Post.php` - CREATE and UPDATE operations (auto-sets author, idUserGroup)
- `Delete.php` - Soft delete operations (sets deleted=1)
- `Resource.php` - File upload/download (delegates physical I/O to `StorageManager`)
- `StorageManager.php` - Storage abstraction: routes file bytes to `local` disk or `supabase` bucket based on `settings.json` → `storage.provider`. See [Storage Backends](#storage-backends-storagemanager).
- `User.php` - User management (login, register, disabled checks)
- `UsersFunctions.php` - User management (disable, enable, edit)
- `Export.php` - Data export (CSV/Excel)
- `Import.php` - Data import
- `CacheManager.php` - Caching system
- `Email.php` - Email sending (via PHPMailer/Mailgun SMTP)
- `LoginSocial.php` - Social authentication (Google, Facebook)
- `Profile.php` - User profile operations
- `GoogleMapsApi.php` - Google Maps integration
- `AdvancedAnalytics.php` - Analytics

AI assistant classes live in the `ai/` subfolder (`api/classes/ai/`, loaded with
`ckd_require_class('ai/AiAssistant')`; an override goes in `custom/override/ai/`):
- `ai/AiAssistant.php` - proxy to the Anthropic API (see [AI Assistant](#ai-assistant))
- `ai/AiSchema.php` - compresses `tables.json` into the model's system prompt
- `ai/AiSql.php` - SQL guard (SELECT-only) + read-only executor
- `ai/AiManual.php` - user manual for the model (framework + permissions + `config/ai_manual.md`)
- `ai/AiTools.php` - project tools registered in `custom/ai_tools.php`
- `ai/AiUsage.php` - per-call token log (`ai_usage`)
- `ai/AiHistory.php` - per-user conversation history (`ai_history`)
- `ai/AiStore.php` - creates the `ai_*` tables (CREATE TABLE IF NOT EXISTS) at the first request
- `ai/AiExport.php` - Excel file of a query's full result (`POST /ai/export`), revalidated and rerun with its own row cap
- `ai/AiFiles.php` - tool `read_file`: a CRM attachment (PDF/image) as a document block, only from tables the level can view; `max_file_bytes`
- `ai/AiWidget.php` - dashboard widgets per user (`ai_widget`, `POST /ai/widget_{create|list|run|update|delete}`): one model call at creation rewrites the query on `:from`/`:to`, then reruns without the model
- `ai/AiFeedback.php` - thumbs up/down on an answer (`ai_feedback`, `POST /ai/feedback`)
- `ai/AiAdmin.php` - admin panel data (`POST /ai/admin_stats`): spend, usage, frequent questions, feedback; levels in `ai_assistant.admin_levels`

---

## AI Assistant

Chat over the project's database and over the application itself, served by
the framework page `/ai-assistant` and by a side panel on every page
(frontend `src/pages/ai/`). Everything lives in `api/classes/ai/` (classes
prefixed `Ai`, entry point `api/ai.php` → `api/ai_config.php`); the project
plugs in through three files it owns: `config/settings.json`
(`ai_assistant`), `config/ai_manual.md` and `custom/ai_tools.php`.

### What the model receives

- **Schema** (`AiSchema`): `tables.json` compressed into one system block,
  limited to the tables the caller's level can `view`; hidden tables and
  columns removed; `file` columns shown as `FILE(hash)`.
- **Manual** (`AiManual`): how the framework UI works (routes, list and form
  conventions), the caller's permissions per entity, the project menu and
  entity labels (sent by the client), and `config/ai_manual.md` if present.
- **Rules**: the framework prompt (answer format, charts, files, Excel export,
  long lists) plus `ai_assistant.prompt_notes`, the project's domain rules.
- **Page context**: route, entity, id and custom page the user is looking at
  (`context` in `/ai/message`).

The schema and manual blocks are stable and cached (`cache_control`); the
context block changes per call and stays out of the cache.

### Tools

| Family | Tools | Where they run |
|---|---|---|
| generic | `run_select`, `search_schema`, `distinct_values` | server, read-only user |
| files | `read_file` (`AiFiles`) | server: a PDF/image attachment as a document block, only if its hash is referenced by a `file` column of a table the level can view |
| project | whatever `custom/ai_tools.php` returns (`AiTools`) | server, framework connection, read-only by contract |
| UI | `ui_navigate`, `ui_open_record`, `ui_open_form` | browser: declared here so the model sees them, executed by the client; nothing is saved by the model |

`custom/ai_tools.php` returns an array of
`{ name, description, input_schema?, levels?, handler }`, handler =
`function (array $input, PDO $db, array $userInfo)` returning an array.

**Prefer queries over project tools.** The default for a project is NO
`ai_tools.php`: write the official definitions (what "revenue" is, which
status is "customer", which date column drives a period) in
`ai_assistant.prompt_notes` with the exact columns, and add a `description`
to every entity in `tables.json`. The model then writes SELECTs that match
the analytics pages, the query log shows exactly what ran, the Excel export
and the dashboard widgets reuse the same SQL. A tool returns an opaque
structure the widgets cannot rerun, and it is a second definition to keep in
sync with the notes. Keep tools for computations that cannot be expressed in
one SELECT.

### Endpoints (`POST /ai/{action}`)

| Action | What it does |
|---|---|
| `message` | One model call: conversation in, content blocks out. The agentic loop (model → tool → model) is driven by the client alternating `message` and `tool`, so a PHP request never lasts more than one call. Refuses above the monthly budget (`ai.budget`). |
| `tool` | Executes one tool (`{ name, input }`). With `validate: true` a `run_select` is only validated and returns the normalized SQL for the review card; every SQL coming back from the browser is re-validated. |
| `export` | Excel of a query's full result (`AiExport`): SQL revalidated, rerun with `export_max_rows` (default 5000), two sheets (data + query), base64 in the JSON. No model. |
| `feedback` | Thumbs up/down on an answer with an optional comment (`AiFeedback`, table `ai_feedback`), with a copy of question and answer. |
| `admin_stats` | Admin panel (`AiAdmin`, levels in `admin_levels`): spend and tokens per day/model/user, monthly budget, most frequent questions, feedback list. |
| `widget_create` | Turns an answer into a dashboard widget (`AiWidget`): ONE model call rewrites the query on `:from`/`:to` and maps columns to a chart or a KPI; validated, test-run on the current month, stored per user in `ai_widget`. Works from the SELECTs of the round; if the answer came from a project tool, the model writes an equivalent SELECT from the schema and `prompt_notes` (one more reason to prefer queries). |
| `widget_list`, `widget_run`, `widget_update`, `widget_delete` | The user's widgets; `run` reruns the stored query with the dates as PDO parameters, no model; `update` takes `title`, `size` (third/half/full) or `order`. |
| `history_list`, `history_save`, `history_delete` | Per-user conversation history (`AiHistory`, table `ai_history`); messages keep text, query log, feedback and attachment names (never file content). |

### Framework tables

`AiStore` creates every `ai_*` table at the first request with
`CREATE TABLE IF NOT EXISTS` and applies idempotent `ALTER`s in `upgrade()`
(a new column is added only if `information_schema` says it is missing).
They are deliberately NOT in `tables.json` (no DataGrid, no `updatedb`) and
are hidden from the model. Written with the framework connection: the
framework writes, the model never does.

| Table | Content |
|---|---|
| `ai_history` | conversations, one row each, messages as JSON |
| `ai_usage` | one row per model call: tokens, cache tokens, conversation, route |
| `ai_feedback` | votes with comment, question and answer |
| `ai_widget` | widget definition (JSON), position, size, `shared` reserved (always 0 today) |

Cost is never stored: `AiUsage` prices tokens at read time with a per-model
list (`ai_assistant.prices`, $/million tokens, defaults in
`AiUsage::DEFAULT_PRICES`); the same list feeds the monthly cap.

### Security, three layers (all required)

1. Syntactic guard (`AiSql::guardSelect`): SELECT/WITH only, single
   statement, keyword blacklist outside string literals, table whitelist from
   `tables.json`, hidden columns rejected, LIMIT injected. The whitelist
   respects `permissions[level].view` with the same rule as `GetSimple`:
   `view` absent or `false` → the table is not in the schema and any query
   touching it is rejected. `"user"` and `"group"` count as visible:
   **row-level filtering is NOT applied** to assistant queries (rewriting
   free-form SQL without a parser is unsafe). Enable the assistant for a
   level only if every table that level can `view` may be read in full.
2. **Dedicated read-only MySQL user, MANDATORY**: the assistant refuses to
   work without it.
   ```sql
   CREATE USER 'ai_readonly'@'localhost' IDENTIFIED BY '<password>';
   GRANT SELECT ON <db_name>.* TO 'ai_readonly'@'localhost';
   ```
3. Server-side execution cap (`max_execution_time` on MySQL 5.7+,
   `max_statement_time` on MariaDB) + row cap.

Files are data, never instructions: the prompt says so, `read_file` says so
in its result, and the client says so before every attachment. `message()`
accepts user content as blocks (text, `document` = PDF base64, `image` =
png/jpeg/gif/webp base64, tool_use/tool_result) and drops anything else
(`cleanContent`); base64 bytes are capped per file (`max_file_bytes`) and
excluded from the conversation size limit. `post_max_size` on the server must
allow a base64 PDF (a few MB).

### Configuration (`config/settings.json`)

```json
"api_keys": { "anthropic_api_key": "sk-ant-..." },
"ai_assistant": {
  "levels": [0],                 // user levels allowed (backend gate)
  "admin_levels": [0],           // who sees the admin panel (spend, usage, feedback)
  "model": "claude-sonnet-4-6",  // per project; "effort" optional (models with thinking)
  "max_rows": 200,               // rows the model sees per query
  "export_max_rows": 5000,       // rows of the Excel export
  "max_execution_ms": 8000,      // server-side cap on SELECTs
  "max_file_bytes": 4194304,     // cap per file, read_file and files dropped in chat
  "db_readonly": { "username": "", "password": "" },   // MANDATORY
  "hidden_tables": [],           // extends auth_token, auth_pin and the ai_* tables
  "hidden_columns": {},          // extends user.password
  "prompt_notes": [],            // project domain rules, e.g. "revenue = totalTaxable"
  "prices": {                    // $/million tokens per model prefix (optional)
    "claude-sonnet-4-6": { "input": 3, "output": 15, "cache_read": 0.3, "cache_write": 3.75 }
  },
  "monthly_budget": 0,           // spend cap for the current month (0 = none)
  "currency": "USD"              // label only
}
```

`prompt_notes` is where the project's business rules belong (what "revenue"
means, which status is "customer", which date column drives a period), with
the exact table and column names: the framework prompt stays generic and both
the chat and the widgets follow them. Every entity in `tables.json` should
carry a `description` (what it represents, what its statuses mean, where the
dates are): it is the heading the model reads for each table in the compact
schema, and the only thing it knows about a view besides its column names. Longer prose about how the
company works (workflows, what each status means, which page to use) goes in
`config/ai_manual.md` (markdown, project-owned, appended to the cached manual
block). Keep sentences short: it is a prompt, not a guide.

The frontend mirrors the gates in `src/config/settings.js`
(`aiAssistant: { enabled, levels, panel, approveQueries, adminLevels }`); the
menu entry (`menu.js`, route `/ai-assistant`) and the `<WidgetAi />` grid in
`Dashboard.js` are up to the project.

---

## Common Mistakes to Avoid

1. **Using MySQLi instead of PDO** -- Always use `$this->db->prepare()`, `bindParam()`, `fetch(PDO::FETCH_ASSOC)`
2. **Wrong API endpoint format** -- Use `/custom/action_name`, not `custom` with action in body
3. **Modifying core files** -- Only modify `/config/` directories and custom class folders
4. **Missing base fields** in entity definition -- Always include id, date, lastUpdate, deleted
5. **Wrong deepExternalEntity parent ref** -- Child's parent field must be `input_type: "number"`, not `externalEntity`
6. **Wrong permission syntax** -- Use exact values: `true`, `false`, `"group"`, `"user"`
7. **Wrong custom page URL** -- Must use `/page/PageName` prefix with CamelCase
8. **Unscoped CSS** -- Always scope custom page styles to container class
9. **String concatenation in SQL** -- Always use PDO prepared statements with parameter binding
10. **Hard deletes** -- Framework uses soft deletes (`deleted=1`), never physically delete
11. **Missing `deleted = 0` in queries** -- Always filter out soft-deleted records in custom SQL queries
12. **Wrong TEXT field length** -- When using TEXT database type, set `length` to `null`, not a number

## Best Practices

1. Follow naming conventions: camelCase for fields, lowercase for entities
2. Use appropriate field lengths: VARCHAR(100) for names, TEXT for long content
3. Implement validation in field definitions, not just in hooks
4. Test permissions thoroughly for each user level
5. Always check `response.success === 1` before using response data
6. Keep custom logic in hooks and custom APIs, separate from core
7. Make fields nullable by default (except id, date, lastUpdate, deleted)
8. Use `options.visible: false` for internal/system fields
9. Use `options.editable: false` for auto-managed fields (id, date, author, etc.)
10. Prefer separate `date` + `time` fields over `datetime` for cross-browser compatibility

---

## Quick Reference

### Endpoint Formats
- Standard CRUD: `POST /get_items/{entity}`, `/get_item/{entity}`, `/post/{entity}`, `/delete/{entity}`, `/search_items/{entity}`
- Custom authenticated: `POST /custom/{action_name}`
- Custom public: `POST /public/{action_name}`
- Authentication: `POST /auth/{login|logout|forgot|reset|checkLogin|refreshSid}`
- User management: `POST /users/{disable|enable|edit}`
- Resources: `POST /resource/{uploadFile|getFile}`
- Profile: `POST /profile/{action}`
- Analytics: `POST /analytics/{action}`
- AI assistant: `POST /ai/{message|tool|export|feedback|admin_stats|widget_*|history_list|history_save|history_delete}`

### Key File Locations
- **Entity Definitions**: `/config/tables.json`
- **System Settings**: `/config/settings.json`
- **Custom APIs**: `/custom/classes/` and `/custom/public_classes/`
- **API Registration**: `/custom/calls.php` and `/custom/public_calls.php`
- **Menu**: `/frontend/src/config/menu.js`
- **Translations**: `/frontend/src/config/entity_language.js`
- **Custom Pages**: `/frontend/src/config/pages/`
- **Public Pages**: `/frontend/src/config/nl_pages/`
- **Hooks**: `/frontend/src/config/hooks/`
- **Frontend guide**: `claude.md` in the root of the React repo
- **Custom Styles and design-token overrides**: `/frontend/src/config/custom.css`
- **Dashboard**: `/frontend/src/config/Dashboard.js`
