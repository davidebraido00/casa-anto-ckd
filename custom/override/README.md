# custom/override — override delle classi standard

Se questa cartella contiene un file con lo stesso nome di una classe standard
(es. `Get.php`), il framework carica QUELLO al posto di `api/classes/Get.php`.

**È un caso limite, da evitare quando possibile.** La strada giusta per la
logica di progetto sono le classi custom in `custom/classes/`. Un override
sostituisce integralmente la classe standard e va riallineato A MANO a ogni
aggiornamento del framework: chi lo usa si prende in carico il diff.

Nota tecnica: dentro un override i require verso le classi standard vanno
fatti con path assoluto (`dirname(__DIR__, 2) . '/api/classes/Nome.php'`),
perché il file non vive più in `api/classes/`.
