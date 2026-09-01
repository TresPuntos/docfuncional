# Plan · Registro de versiones aprobadas

> **Origen:** al preparar el contrato de Dixie (1-sep-2026) se detectó que el documento funcional que el cliente aprobó **no es el que está publicado**. El contrato necesitaba poder señalar «esto es exactamente lo que se firmó» y la aplicación no lo permite hoy.
>
> **Estado (1-sep-2026): IMPLEMENTADO y desplegado** — commit `bdc62db`. Ver la sección
> "✅ DESPLEGADO 2026-09-01 · Registro de versiones aprobadas" de `CLAUDE.md` para lo que
> se construyó realmente, y `REFERENCIA-CONTRATO-DIXIE.md` para la referencia y la cláusula.
>
> **Correcciones a este plan, comprobadas contra el código y la BD de producción:**
>
> 1. En Dixie **no hubo suerte**: la fila "v1.0" del historial se archivó el 22-jul y ya
>    contiene texto de la reunión del 22-jul, posterior a la firma del 17-jul. El documento
>    que Claudia firmó no existe. El contrato remite a la v1.2, no a la v1.0.
> 2. **Las re-firmas no se registraban** (`view.php` solo insertaba si no había ninguna
>    aprobación previa de ese tipo). No estaba en este plan y era lo primero que fallaba.
> 3. **`?v=` por etiqueta es ambiguo**: `propuestas_history` no tiene UNIQUE y tanto
>    `restore_version` como el `new_version` de `admin.php` pueden crear filas repetidas con
>    la misma etiqueta. Se resuelve por `history_id`, y se añadió `aprobaciones.history_id`
>    para que la aprobación apunte a la fila concreta.
> 4. **La vista `?v=` tenía efectos secundarios**: `view.php` hace `tasks_sync` y
>    `respuestas_sync` (UPSERT de lo declarado en el HTML) y tracking. Servir un HTML viejo
>    sin blindar eso habría sembrado tareas y preguntas antiguas en las tablas vivas.
> 5. El gate de PIN **se comía el `?v=`** al redirigir tras el login.
>
> Pendiente del plan original, no bloqueante: D (aviso al editar propuesta aprobada) y
> E (exponer la huella en la API).

---

## 1. El problema, con datos

Una propuesta se puede editar después de haber sido aprobada. La aprobación guarda `version_firmada`, pero esa versión ya no es la que sirve `/p/<slug>`. Resultado: **la aprobación apunta a un documento que ya no existe en pantalla**.

No es un caso aislado. Las cuatro propuestas con aprobación registrada están desfasadas:

| propuesta_id | slug | version_firmada | version actual | Desfase |
|---|---|---|---|---|
| 21 | h2bhipotecas | v1.5 | v1.9 | 4 versiones |
| 24 | gibobs-allbanks | *(vacío)* | v2.2 | sin versión registrada |
| 34 | diptron | v1.2 | v1.3 | 1 versión |
| 35 | dixie | v1.0 | v1.2 | 2 versiones |

Además, **la versión firmada puede no estar conservada**: `propuestas_history` solo guarda las versiones archivadas con `save_version`. En Dixie hubo suerte (existen v1.0 y v1.1), pero no está garantizado.

### 1.1 El hash actual no sirve para probar integridad

`view.php` construye el hash de la firma así:

```php
$payload = $propId . '|' . $tipo . '|' . $signer['nombre'] . '|' . $signer['apellidos'] . '|' . $version . '|' . date('c');
```

Es un hash **de los metadatos de la firma**, no del documento. Prueba quién firmó y cuándo, pero **no prueba qué firmó**: si el contenido cambia, el hash sigue siendo válido. Para un contrato que remite al funcional, eso es justo lo que hace falta y no hay.

---

## 2. Qué hay que construir

### A · Huella del contenido en el momento de aprobar  ← imprescindible

Nueva columna en `aprobaciones`:

```sql
ALTER TABLE aprobaciones ADD COLUMN contenido_hash TEXT;
```

En `view.php`, al registrar la aprobación, calcular y guardar:

```php
$contenidoHash = hash('sha256', $proposal['html_content']);
```

Y mostrarlo en el acuse que ve el cliente y en el panel de admin, junto a la versión.

### B · Congelar la versión aprobada  ← imprescindible

Al aprobar, si esa versión no está ya en `propuestas_history`, **insertarla**. Hoy solo se archiva al hacer `save_version` desde la API, así que una versión puede aprobarse y perderse.

Añadir también la huella al historial:

```sql
ALTER TABLE propuestas_history ADD COLUMN contenido_hash TEXT;
```

### C · Vista de solo lectura de una versión concreta  ← imprescindible

Poder abrir la versión exacta que se firmó, sin posibilidad de editarla:

```
/p/<slug>?v=v1.0     →  sirve propuestas_history.html_content de esa versión
```

Requisitos: mismo control de acceso por PIN que la vista normal · banner visible («Versión archivada v1.0 · aprobada el 17-jul-2026 · huella e806c21e…») · sin controles de firma ni de edición · devolver 404 si la versión no existe.

Esa es la URL que se pega en los contratos.

### D · Aviso al editar una propuesta aprobada  ← recomendable

En `admin.php`, si la propuesta tiene filas en `aprobaciones`, mostrar antes de guardar:

> ⚠️ Esta propuesta fue aprobada por [firmante] el [fecha] en la versión [v1.0]. Si la editas, la aprobación quedará referida a una versión anterior. Considera guardar como versión nueva y pedir aprobación de nuevo.

No bloquear: avisar. A veces la edición es una corrección menor y no procede refirmar.

### E · Huella en la API  ← recomendable

`GET /api/proposals.php?id=X&history=1` devuelve ya el historial. Añadir `contenido_hash` a cada entrada y a la versión vigente, para poder citar la huella en un contrato sin calcularla a mano.

---

## 3. Orden de trabajo

1. Migración idempotente `database/migrate_versiones.php` con las dos columnas nuevas (A y B).
2. Backfill: calcular la huella de las versiones ya guardadas en `propuestas_history`.
3. `view.php`: guardar `contenido_hash` al aprobar + archivar la versión si no está (A y B).
4. `view.php`: modo lectura de versión archivada por `?v=` (C).
5. `admin.php`: aviso al editar propuesta aprobada (D).
6. `api/proposals.php`: exponer la huella (E).

**Mínimo viable para que los contratos puedan remitir a la app: 1, 2, 3 y 4.**

## 4. Deploy

Seguir el flujo del proyecto: `scripts/deploy/backup-prod.sh` antes de nada · migración idempotente, que se pueda ejecutar dos veces sin romper · `scripts/deploy/smoke-test.sh` después · y rollback disponible con `scripts/deploy/rollback.sh`.

La base de producción es SQLite en `<docroot>/doc/database/database.sqlite`. **Hacer copia antes de migrar.**

## 5. Deuda que esto no resuelve

Las cuatro aprobaciones existentes seguirán sin huella de contenido, porque se firmaron antes de que existiera el campo. Para h2b, Diptron y Dixie se puede reconstruir la huella **si la versión firmada está en el historial**; si no está, esa aprobación queda sin documento verificable y lo honesto es anotarlo. Gibobs, además, se aprobó sin registrar versión.
