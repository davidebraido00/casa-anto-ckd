#!/usr/bin/env python3
"""
Genera le entità del negozio Casa Anto dentro config/tables.json.

Le entità base del framework (user, user_group, auth_token, ...) restano come
sono; quelle del negozio vengono (ri)scritte da qui. Lanciare dalla root del
backend:

    python3 custom/setup/build_tables.py

Poi aprire /utils/ → "Aggiorna il database" e applicare custom/setup/views.sql.
"""
import json
import os

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PATH = os.path.join(ROOT, 'config', 'tables.json')

ADMIN_ONLY = {"0": {"view": True, "create": True, "edit": True, "delete": True}}
READ_ONLY = {"0": {"view": True, "create": False, "edit": False, "delete": False}}


def base():
    return {
        "id": {"type": "INT", "length": "11", "input_type": "number", "null": False,
               "options": {"visible": False, "editable": False}},
        "date": {"type": "TIMESTAMP", "length": "30", "input_type": "datetime", "null": False,
                 "default": "CURRENT_TIMESTAMP", "options": {"visible": False, "editable": False}},
        "lastUpdate": {"type": "TIMESTAMP", "length": "30", "input_type": "datetime", "null": False,
                       "default": "CURRENT_TIMESTAMP", "on_update": "CURRENT_TIMESTAMP",
                       "options": {"visible": False, "editable": False}},
        "deleted": {"type": "INT", "length": "2", "input_type": "boolean", "null": False, "default": 0,
                    "options": {"visible": False, "editable": False}},
    }


def f(type_, length, input_type, visible=True, editable=True, required=False, **extra):
    opts = {"visible": visible, "editable": editable}
    col = {"type": type_, "length": length, "input_type": input_type, "null": True,
           "options": opts, "validation": {"required": required}}
    for k, v in extra.items():
        if k in ("unique", "default"):
            col[k] = v
        elif k in ("min", "max"):
            col["validation"][k] = v
        else:
            opts[k] = v
    return col


def string(length="100", **kw): return f("VARCHAR", length, "string", **kw)
def text(**kw): return f("TEXT", None, "text", **kw)
def markdown(**kw): return f("TEXT", None, "markdown", **kw)
def number(**kw): return f("INT", "11", "number", **kw)
def currency(**kw): return f("DECIMAL", "10,2", "currency", **kw)
def floatf(**kw): return f("DECIMAL", "10,2", "float", **kw)
def boolean(default=0, **kw): return f("INT", "1", "boolean", default=default, **kw)
def date(**kw): return f("DATE", None, "date", **kw)
def datetime(**kw): return f("DATETIME", None, "datetime", **kw)
def email(**kw): return f("VARCHAR", "150", "email", **kw)
def phone(**kw): return f("VARCHAR", "40", "phone", **kw)
def file(**kw): return f("VARCHAR", "500", "file", **kw)


def option(values, default=None, length="30", **kw):
    col = f("VARCHAR", length, "option", **kw)
    col["options"]["options"] = [{"value": v, "bg": bg} for v, bg in values]
    if default is not None:
        col["options"]["default"] = default
        col["default"] = default
    return col


def ext(entity, label, required=False, **kw):
    return f("INT", "11", "externalEntity", entity=entity, labelField=label, required=required, **kw)


def deep(entity, label, source, visibleColumns=None, **kw):
    col = {"type": "NONE", "length": "0", "input_type": "deepExternalEntity", "null": True,
           "options": {"visible": False, "editable": True, "entity": entity,
                       "labelField": label, "source_field": source}}
    if visibleColumns:
        col["options"]["visibleColumns"] = visibleColumns
    col["options"].update(kw)
    return col


def parent_ref():
    return {"type": "INT", "length": "11", "input_type": "number", "null": True,
            "options": {"visible": False, "editable": False}}


def entity(default_key, columns, permissions=ADMIN_ONLY, description=None):
    cols = base()
    cols.update(columns)
    e = {"primary_key": "id", "default_key": default_key}
    if description:
        e["description"] = description
    e["columns"] = cols
    e["permissions"] = permissions
    return e


ORDER_STATUS = [
    ("in_attesa_pagamento", "warning"),
    ("pagato", "info"),
    ("in_preparazione", "primary"),
    ("spedito", "primary"),
    ("consegnato", "success"),
    ("annullato", "danger"),
    ("rimborsato", "secondary"),
]
LANG = [("it", "secondary"), ("en", "secondary")]

SHOP = {
    "product": entity("name_it", {
        "name_it": string(required=True),
        "name_en": string(visible=False),
        "slug": string("120", unique=True, required=True),
        "subtitle_it": string("255", visible=False),
        "subtitle_en": string("255", visible=False),
        "description_it": markdown(visible=False, rows=10),
        "description_en": markdown(visible=False, rows=10),
        "tastingNotes_it": text(visible=False),
        "tastingNotes_en": text(visible=False),
        "pairings_it": text(visible=False),
        "pairings_en": text(visible=False),
        "cultivar": string(visible=False),
        "acidity": string("50", visible=False),
        "harvest": string(visible=False),
        "harvest_en": string(visible=False),
        "intensity": option([("delicato", "success"), ("medio", "warning"), ("intenso", "danger")], default="medio"),
        "image": file(),
        "image2": file(visible=False),
        "image3": file(visible=False),
        "image4": file(visible=False),
        "featured": boolean(),
        "active": boolean(default=1),
        "sortOrder": number(default=0),
        "variants": deep("product_variant", "format", "idProduct",
                         ["format", "sku", "price", "lowStockThreshold", "active"]),
    }, description="Oli in vendita. Testi in italiano (_it) e inglese (_en). Il prezzo e la giacenza sono sulle varianti (product_variant)."),

    "product_variant": entity("fullName", {
        "idProduct": parent_ref(),
        "format": string("50", required=True),
        "sku": string("60", unique=True),
        "price": currency(required=True, min=0),
        "compareAtPrice": currency(visible=False, min=0),
        "weightGrams": number(visible=False),
        "lowStockThreshold": number(default=10),
        "active": boolean(default=1),
        "sortOrder": number(visible=False, default=0),
        "fullName": string("200", editable=False),
    }, description="Formato vendibile di un prodotto (es. Classico 500 ml) con prezzo IVA inclusa. La giacenza non è un campo: è la somma di stock_movement.quantity."),

    "stock_movement": entity("id", {
        "idVariant": ext("product_variant", "fullName", required=True, visibleColumns=["fullName", "sku"]),
        "type": option([("carico", "success"), ("scarico", "danger"), ("rettifica", "warning"),
                        ("vendita_online", "primary"), ("vendita_diretta", "info"),
                        ("reso", "secondary"), ("annullo", "secondary")], default="carico", required=True),
        "quantity": number(required=True),
        "idOrder": ext("shop_order", "number", editable=False),
        "notes": text(),
    }, description="Movimenti di magazzino. quantity è con segno: positivo = entrata, negativo = uscita. Giacenza variante = SUM(quantity)."),

    "customer": entity("email", {
        "firstName": string(),
        "lastName": string(),
        "email": email(unique=True, required=True),
        "phone": phone(),
        "passwordHash": string("255", visible=False, editable=False, private=True),
        "language": option(LANG, default="it", length="5"),
        "marketingConsent": boolean(),
        "disabled": boolean(),
        "lastLoginAt": datetime(editable=False, visible=False),
        "notes": text(visible=False),
        "addresses": deep("customer_address", "address", "idCustomer",
                          ["label", "address", "city", "zip", "country", "isDefault"]),
    }, description="Clienti registrati sul sito. Gli ordini da ospite hanno idCustomer vuoto e si riconoscono dall'email."),

    "customer_address": entity("address", {
        "idCustomer": parent_ref(),
        "label": string("60"),
        "firstName": string(),
        "lastName": string(),
        "company": string(visible=False),
        "address": string("255", required=True),
        "city": string(required=True),
        "zip": string("20", required=True),
        "province": string("60"),
        "country": string("2", required=True, default="IT"),
        "phone": phone(visible=False),
        "isDefault": boolean(),
    }),

    "customer_token": entity("token", {
        "idCustomer": number(),
        "token": string("128", unique=True),
        "type": string("20"),
        "expiresAt": datetime(),
    }, permissions={}),

    "shop_order": entity("number", {
        "number": string("30", unique=True, editable=False),
        "channel": option([("online", "primary"), ("diretta", "info")], default="online"),
        "status": option(ORDER_STATUS, default="in_attesa_pagamento", required=True),
        "paymentMethod": option([("stripe", "primary"), ("paypal", "info"), ("contanti", "success"),
                                 ("pos", "secondary"), ("bonifico", "secondary")]),
        "paymentRef": string("255", visible=False),
        "paidAt": datetime(),
        "paymentFee": currency(visible=False),
        "idCustomer": ext("customer", "email", visibleColumns=["firstName", "lastName", "email"]),
        "email": email(),
        "firstName": string(),
        "lastName": string(),
        "phone": phone(visible=False),
        "shipAddress": string("255", visible=False),
        "shipCity": string(visible=False),
        "shipZip": string("20", visible=False),
        "shipProvince": string("60", visible=False),
        "shipCountry": string("2", visible=False),
        "shippingZone": string(visible=False, editable=False),
        "subtotal": currency(visible=False, editable=False),
        "discountCode": string("50", visible=False, editable=False),
        "discountAmount": currency(visible=False, editable=False),
        "shippingCost": currency(visible=False, editable=False),
        "total": currency(editable=False),
        "language": option(LANG, default="it", length="5", visible=False),
        "customerNotes": text(visible=False),
        "internalNotes": text(visible=False),
        "trackingCarrier": string("60", visible=False),
        "trackingNumber": string("100", visible=False),
        "trackingUrl": string("500", visible=False),
        "shippedAt": datetime(visible=False),
        "stockApplied": boolean(visible=False, editable=False),
        "checkoutRef": string("255", visible=False, editable=False),
        "accessToken": string("64", visible=False, editable=False, private=True),
        "items": deep("shop_order_item", "productName", "idOrder",
                      ["productName", "format", "unitPrice", "quantity", "lineTotal"], editable=False),
    }, description="Ordini. channel=online (sito) o diretta (vendita in azienda). Incassato = status in (pagato, in_preparazione, spedito, consegnato), data di riferimento paidAt. Importi IVA inclusa."),

    "shop_order_item": entity("productName", {
        "idOrder": parent_ref(),
        "idVariant": ext("product_variant", "fullName"),
        "productName": string("200"),
        "format": string("50"),
        "sku": string("60"),
        "unitPrice": currency(),
        "quantity": number(),
        "lineTotal": currency(),
    }, description="Righe d'ordine, con nome e prezzo copiati al momento dell'acquisto."),

    "discount_code": entity("code", {
        "code": string("50", unique=True, required=True),
        "description": string("255"),
        "type": option([("percentuale", "primary"), ("importo", "info"), ("spedizione_gratuita", "success")],
                       default="percentuale", required=True),
        "value": floatf(min=0),
        "minOrder": currency(min=0),
        "startsAt": date(),
        "endsAt": date(),
        "maxUses": number(),
        "usedCount": number(editable=False, default=0),
        "active": boolean(default=1),
    }, description="Codici sconto. percentuale: value = % sul subtotale; importo: value = euro; spedizione_gratuita: azzera la spedizione."),

    "review": entity("title", {
        "idProduct": ext("product", "name_it", required=True),
        "idCustomer": ext("customer", "email", editable=False),
        "idOrder": ext("shop_order", "number", editable=False, visible=False),
        "authorName": string(),
        "rating": number(required=True, min=1, max=5),
        "title": string("200"),
        "body": text(),
        "status": option([("in_attesa", "warning"), ("approvata", "success"), ("rifiutata", "danger")],
                         default="in_attesa"),
        "language": option(LANG, default="it", length="5", visible=False),
    }, description="Recensioni dei clienti che hanno acquistato il prodotto. Visibili sul sito solo se status=approvata."),

    "stock_alert": entity("email", {
        "idVariant": ext("product_variant", "fullName", required=True),
        "email": email(required=True),
        "language": option(LANG, default="it", length="5"),
        "status": option([("attivo", "warning"), ("notificato", "success"), ("annullato", "secondary")],
                         default="attivo"),
        "notifiedAt": datetime(editable=False),
    }, description="Richieste 'avvisami quando torna disponibile'."),

    "shipping_zone": entity("name", {
        "name": string(required=True),
        "name_en": string(),
        "countries": string("500", required=True),
        "cost": currency(required=True, min=0),
        "freeThreshold": currency(min=0),
        "deliveryDays": string("50"),
        "deliveryDays_en": string("50", visible=False),
        "active": boolean(default=1),
        "sortOrder": number(visible=False, default=0),
    }, description="Zone di spedizione. countries = codici ISO2 separati da virgola (es. IT,SM,VA). freeThreshold = subtotale oltre cui la spedizione è gratuita."),

    "shop_settings": entity("shopName", {
        "shopName": string(required=True),
        "notificationEmail": email(),
        "contactEmail": email(),
        "contactPhone": phone(),
        "address": string("255"),
        "vatNumber": string("40"),
        "orderPrefix": string("10", default="CA"),
        "reviewsAutoApprove": boolean(),
    }, description="Impostazioni del negozio (una sola riga, id=1)."),
}

VIEWS = {
    "gyv_stock": entity("fullName", {
        "idProduct": ext("product", "name_it", editable=False),
        "fullName": string("200", editable=False),
        "format": string("50", editable=False, visible=False),
        "sku": string("60", editable=False),
        "price": currency(editable=False),
        "stock": number(editable=False),
        "lowStockThreshold": number(editable=False),
        "stockStatus": option([("esaurito", "danger"), ("basso", "warning"), ("ok", "success")], editable=False),
        "stockValue": currency(editable=False),
        "active": boolean(editable=False),
    }, permissions=READ_ONLY, description="Giacenza attuale per variante (vista). stockValue = stock * prezzo."),
}

REMOVE = ["test", "category"]

with open(PATH) as fh:
    tables = json.load(fh)
for name in REMOVE:
    tables.pop(name, None)
tables.update(SHOP)
tables.update(VIEWS)
with open(PATH, "w") as fh:
    json.dump(tables, fh, indent=4, ensure_ascii=False)
    fh.write("\n")
print("tables.json:", ", ".join(tables.keys()))
