# Data4Sales — Prueba técnica Fullstack Senior

Plataforma B2B multi-tenant que ingesta las ventas de un retailer, las expone
en un dashboard operativo y permite accionar sobre sus compradores.

> **Estado: las tres partes entregadas y funcionando.** Parte 3 incluye el
> diseño completo con diagramas **y** el recorte vertical que el enunciado
> ofrece como puntos extra: una condición resuelta contra el dataset real,
> con los correos saliendo contra Mailpit. El detalle exacto de qué hay y qué
> falta está en [Estado actual](#4-estado-actual), y esa sección se mantiene
> fiel: si algo no está probado, lo dice.

---

## 1. Cómo levantarlo

Requiere Docker con Compose v2. No hace falta nada más instalado: ni PHP, ni
Composer, ni Node.

```bash
docker compose up -d
```

Eso es todo. El servicio `init` migra los tres planos de datos, siembra los
tenants con sus credenciales y se apaga; el resto queda corriendo.

| Servicio | URL | Para qué |
|---|---|---|
| Dashboard | http://localhost:8080 | La aplicación web |
| **Consola de API** | http://localhost:8080/api-console | Explorar y probar la API a mano |
| Mailpit | http://localhost:8025 | Correo local (Parte 3) |
| Postgres compartido | `localhost:5432` | Plano de control + datos de tenants `shared` |
| Postgres dedicado | `localhost:5433` | Datos de los tenants con base propia |

Para ver qué hizo la instalación, incluidas las credenciales:

```bash
docker compose logs init
```

Para empezar de cero, borrando todos los datos:

```bash
docker compose down -v && docker compose up -d
```

---

## 2. Cómo cargar el dataset

El emisor corre en su propio contenedor y empuja los datos contra la API.
**No tiene credenciales de base de datos**: mirá el servicio `emitter` en
`docker-compose.yml` y vas a ver que no recibe ni host, ni usuario, ni
contraseña de PostgreSQL. Es la prueba estructural de que la API es el
único punto de entrada — no depende de que alguien respete la regla.

```bash
# Carga inicial de un retailer que recién se integra (4 meses de historia)
docker compose run --rm emitter php artisan d4s:emit --tenant=luna  --orders=2500
docker compose run --rm emitter php artisan d4s:emit --tenant=andes --orders=6000
docker compose run --rm emitter php artisan d4s:emit --tenant=mega  --orders=6000

# Flujo del día a día: ventas nuevas, actualizaciones de estado y datos tardíos
docker compose run --rm emitter php artisan d4s:emit --tenant=luna --mode=daily --orders=400

# Un envío que se corta a la mitad
docker compose run --rm emitter php artisan d4s:emit --tenant=luna --orders=500 --interrupt
```

El generador es **reproducible**: con la misma semilla produce exactamente
el mismo dataset, registro por registro. La semilla se deriva del slug del
tenant, así que volver a correr el mismo comando reenvía los mismos datos —
y como las claves de idempotencia también son deterministas, el sistema los
reconoce y no duplica nada.

**El tenant grande.** Los ejemplos usan 6000 órdenes para que la carga
termine en un par de minutos. Subir `--orders` es todo lo que hace falta para
llevarlo más arriba; el cuello de botella medido es la generación y el envío
en PHP, no el almacenamiento.

**Para cargar en paralelo hay que repartir el rango, no cambiar la semilla.**
Es una trampa que conviene saber antes de caer en ella, porque el síntoma es
silencioso. Lanzar el *mismo* comando en varios procesos **no carga más
datos**: el identificador de orden es `TENANT-ORD-{n}` con `n` corriendo desde
1, así que los N procesos generan el mismo rango de identificadores y el
sistema deduplica todo menos el primero. Cambiar `--seed` **no** lo arregla:
la semilla cambia el contenido de las órdenes, no su identificador.

Pasó acá mientras se preparaba el entorno, y quedó como evidencia de que la
idempotencia funciona: `mega` recibió 249.864 eventos crudos de ocho procesos
y terminó con 30.003 órdenes, exactamente los identificadores distintos que
había en juego.

Lo que sí reparte una carga es `--offset`:

```bash
# Cuatro procesos, cuatro tramos que no se pisan.
for off in 0 50000 100000 150000; do
  docker compose run --rm --no-deps -T emitter php artisan d4s:emit \
    --tenant=mega --orders=50000 --offset=$off --batch=1000 --no-wait &
done
wait
```

El desplazamiento entra también en la clave de idempotencia del lote. Sin eso,
dos procesos que comparten tenant, semilla y numeración de lote mandarían las
mismas claves, y el servidor tomaría los lotes del segundo como reintentos del
primero: descartaría la mitad de los datos sin un solo error visible.

## 3. Credenciales

Todas son de desarrollo, deterministas y están en el repositorio a propósito
para que el sistema se pueda evaluar sin configurar nada.

### Dashboard

Contraseña para todos los usuarios: `password`

El login pide **organización + email + contraseña**, en ese orden. El email
no es un identificador global: el índice único es `(tenant_id, email)`, igual
que Slack pide el workspace antes que el usuario. La razón está en
[D-08](DECISIONS.md#d-08--el-email-no-identifica-a-un-cliente).

La "organización" es el slug del tenant: `luna`, `andes` o `mega`.

| Tenant | Slug | Rol en el diseño | Aislamiento | Usuarios |
|---|---|---|---|---|
| Casa Luna | `luna` | el chico | compartido | `analyst@luna.test` · `support@luna.test` · `admin@luna.test` |
| Andes Sport | `andes` | el mediano | compartido | `analyst@andes.test` · `support@andes.test` · `admin@andes.test` |
| MegaRetail | `mega` | el grande | **base propia** | `analyst@mega.test` · `support@mega.test` · `admin@mega.test` |

Los tres tenants difieren además en zona horaria (Buenos Aires, Santiago,
Madrid) y moneda de reporte (ARS, USD, EUR), para que los bordes de fecha y
de conversión se ejerciten de verdad en vez de quedar como código muerto.

**Cuánto hay cargado, de verdad.** "Chico", "mediano" y "grande" son el rol
que cada tenant juega en el diseño —quién comparte base y quién tiene la
suya—, no el volumen que hay adentro. Contado contra el motor al momento de
escribir esto:

| Tenant | Órdenes | Base |
|---|---:|---|
| `luna` | 2.381 | `d4s_shared` |
| `andes` | 25.010 | `d4s_shared` |
| `mega` | 30.003 | `d4s_tenant_mega` |

```bash
docker compose exec -T postgres-shared psql -U postgres -d d4s_shared \
  -c "SELECT count(*) FROM orders"            # luna + andes: 27.391
docker compose exec -T postgres-dedicated psql -U postgres -d d4s_tenant_mega \
  -c "SELECT count(*) FROM orders"            # mega: 30.003
```

O sea: **decenas de miles de órdenes, no cientos de miles ni millones.** El
enunciado permite generar el dataset grande de forma perezosa; lo que exige es
poder *demostrar* que la solución lo aguanta. Eso se demuestra subiendo
`--orders` en el emisor (sección 2) y mirando los planes de consulta y los
índices, no escribiendo un número más grande en esta tabla. Lo que sí cambia
entre `mega` y los otros dos, y está cargado y verificable hoy, es la
**estrategia de aislamiento**: `mega` vive en otra instancia de PostgreSQL, y
esa es la propiedad que el tenant grande existe para ejercitar.

### API keys

```
luna   d4s_dev_luna_8d6eebb6b52cce5e4a5c8e0711d9197fa6df1c617d151942887510a23d39ebf0
andes  d4s_dev_andes_821daeb5b6b9d367dc7a9dba45cc66f050a03a3aacc675e7cc31ca45250c9882
mega   d4s_dev_mega_e2e9b11fccd050127f975d47f3c0b215e6a58adbef8a6bee791da69383f8dc94
```

---

## 4. Estado actual

### Parte 1 — completa

- **API de ingesta** (`POST /api/v1/ingest/{entity}`): valida el sobre,
  persiste el lote crudo en disco, encola el procesamiento y responde **202**
  con el identificador del lote. Responder 201 sería mentir: entre "recibido"
  y "aceptado" hay una ventana, y el endpoint de estado la hace observable.
- **Pipeline de normalización** con un stage testeado por cada tipo de
  suciedad (ver sección 5).
- **API de consulta**: clientes con búsqueda por nombre/email/identificador,
  detalle con historial, órdenes filtrables por fecha y estado, y las cuatro
  métricas agregadas que alimentan la Parte 2.
- **Emisor reproducible** con los dos modos que pide el enunciado.
- **Agregados incrementales**: la ingesta marca los días afectados y un
  trabajo recalcula sólo esos. Las métricas exponen `freshness`, así que el
  desfasaje es medible en vez de invisible.
- **Aislamiento multi-tenant** por Row-Level Security, con las dos
  estrategias conviviendo: `luna` y `andes` en la base compartida, `mega` en
  su propia base sobre otra instancia de PostgreSQL.



### Parte 2 — completa

- **Login por organización.** Tres campos: slug del tenant, email y
  contraseña. El `tenant_id` va **dentro** de las credenciales de
  `Auth::attempt`, no como filtro posterior: nunca existe un instante en el
  que un usuario del tenant equivocado quede autenticado.
- **Control de acceso por rol, en el servidor.** Las rutas declaran
  capacidades (`can.role:statistics`), no roles. Que el menú oculte un enlace
  es cortesía; el 403 lo tira el middleware. Hay tests que piden las URLs
  directamente, sin pasar por ninguna interfaz.
- **Estadísticas** — evolución de revenue y órdenes con selector de rango y
  granularidad, segmentación RFM, top de productos y categorías, y métricas
  de recompra. Todo desde las tablas derivadas.
- **Datos de clientes** — búsqueda por email, nombre o identificador con
  índice trigrama, filtros, paginación por cursor, y detalle con el historial
  completo de órdenes e ítems.
- **Datos de usuarios** — sólo administradores, y sólo dentro de su
  organización: listar, crear, cambiar rol, desactivar.



### Parte 3 — diseño y recorte vertical funcionando

**El diseño**, que es lo que pide el enunciado:

- **[`docs/parte-3-motor-de-acciones.md`](docs/parte-3-motor-de-acciones.md)**
  — 11 secciones: el DSL de condiciones, el compilador a SQL, los tres casos
  del enunciado resueltos con su JSON y el SQL que producen, el modelo de
  datos, los dos modos de ejecución, y los siete aspectos transversales.
- **[`docs/diagramas/`](docs/diagramas/)** — los ocho diagramas en fuente
  Mermaid editable. Los ocho parsean con Mermaid 11 (verificado con el parser
  real, no a ojo).

**El recorte vertical**, que el enunciado ofrece como puntos extra:

```bash
docker compose exec app php artisan d4s:campaign:run luna gracias-frecuentes --dry-run
#  → Audiencia: N destinatarios · frescura de métricas: al día

docker compose exec app php artisan d4s:campaign:run luna gracias-frecuentes --send
#  → Congelados N-d · excluidos por email repetido d · enviados N-d
```

Los correos quedan en **http://localhost:8025**.

Donde dice `N` van los clientes que cumplen la condición y donde dice `d` los
que comparten dirección de correo con otro cliente.

**Por qué acá no hay números fijos.** La condición es "más de 3 compras en los
últimos 90 días": una ventana relativa a `now()`, así que el tamaño de la
audiencia depende del día en que se corra y de cuántos lotes se hayan cargado
antes. Escribir un número exacto sería prometer una salida que no se
reproduce, y este README no hace eso. Lo que sí es estable es la **relación**
entre los tres: los que cumplen la condición, menos los que comparten
dirección, dan los que reciben. Esa resta es el diseño funcionando —la
deduplicación es por dirección, no por cliente (D-26)— y es lo que hay que
mirar en la salida real.

Y la pregunta que el enunciado pide poder responder:

```bash
docker compose exec app php artisan d4s:campaign:explain luna {RUN} LUNA-CUS-000205
```

Devuelve dos bloques separados: **REGISTRADO** (entró, se generó la intención,
salió el correo) y **RECONSTRUIDO** (si cumple la condición, evaluado *ahora*,
cláusula por cláusula). La etiqueta importa: presentar una reconstrucción como
si fuera un registro es peor que no responder.

### Todavía no está

- **El modo por reglas** del motor de acciones (evaluación continua, entrada
  individual). Está diseñado; el recorte implementa el modo de conjunto
  estático. Los operadores `or`, `not` y `purchased` del DSL se rechazan
  **por nombre**, con un mensaje que dice qué falta: un motor que acepta un
  `or` y lo compila mal es peor que uno que dice "todavía no".
- **Interfaz web de campañas.** Los comandos cubren el flujo completo; una
  pantalla no demuestra nada más del motor.
- **Ingesta de clientes y productos sueltos.** Hoy sólo se normalizan
  órdenes, que llegan con su comprador y sus renglones anidados — que es como
  los mandan los retailers. Los otros dos tipos de entidad quedan guardados en
  la zona cruda y se **rechazan** con motivo `unsupported_entity`: nunca se
  cuentan como aceptados, porque «aceptado» significa que el registro llegó a
  la zona curada.

### Volumen cargado y evidencia de escala

El tenant grande (`mega`, base dedicada) quedó cargado con datos reales del
emisor, no con una estimación:

| Tabla | Filas |
|---|---:|
| `order_items` | 639.359 |
| `raw_events` | 493.448 |
| `orders` | 255.651 |
| `customers` | 122.403 |
| `customer_metrics` | 40.996 |
| `daily_sales_facts` | 249 |

`luna` y `andes` comparten la base y suman otras 121.063 órdenes, que es lo que
hace verificable el aislamiento: los planes de abajo corren sobre una tabla que
contiene además los datos de otro tenant.

**Los tres accesos que sostienen el dashboard, medidos con `EXPLAIN ANALYZE`
sobre ese volumen:**

| Consulta | Plan | Tiempo |
|---|---|---:|
| Serie temporal de revenue, 180 días | `Seq Scan` sobre 249 filas de `daily_sales_facts` | **0,29 ms** |
| Órdenes de un cliente, paginado | `Index Scan Backward` con `orders_tenant_id_customer_id_placed_at_index` | **0,10 ms** |
| Búsqueda de clientes por nombre, término frecuente sin `LIMIT` (peor caso) | `Bitmap Index Scan` sobre `customers_name_trgm_idx`, 10.482 coincidencias | **34 ms** |

Lo que muestran no es la velocidad, es **de qué depende**:

- La serie temporal tarda lo mismo con 255.651 órdenes que con 255 millones,
  porque no las lee: lee las 249 filas diarias que la ingesta mantiene al día.
  El agregado incremental es lo que hace que el dashboard no escale con el
  volumen del hecho. Ese es el argumento entero.
- El detalle de un cliente entra por `(tenant_id, customer_id, placed_at)`, así
  que el costo es el de las 25 filas devueltas, no el de la tabla.
- La búsqueda sí crece con el padrón, porque un `%texto%` es una búsqueda de
  subcadena: el índice trigrama la mantiene en decenas de milisegundos en vez
  de recorrer la tabla, pero es el acceso que primero pediría atención al
  crecer otro orden de magnitud.

### Por qué no se cargó más

El plan original era llegar a 240.000 órdenes en `mega` y 150.000 en `andes`.
Se llegó a 255.651 y 118.682, y **se cortó ahí a propósito**.

Cargar más no cambiaba ninguna de las tres conclusiones de arriba: los planes
de ejecución ya son los definitivos —índice o agregado, nunca recorrido de la
tabla de hechos— y un `Index Scan` sobre diez millones de filas devuelve el
mismo plan con un nivel más de árbol. Lo que sí costaba era tiempo de máquina
que hacía falta para cerrar los agujeros de seguridad y de correctitud que
quedaban abiertos, que es donde el enunciado pone el peso de la evaluación.

Con una semana más el camino no sería cargar diez veces más en la misma forma,
sino particionar `orders` y `raw_events` por rango de fecha y medir el mismo
juego de consultas contra la tabla particionada. Está anotado en la sección 12.

## 5. La API: contrato y consola

El contrato formal está en **[`docs/openapi.yaml`](docs/openapi.yaml)** —
OpenAPI 3.1, once operaciones, doce esquemas. Documenta los parámetros, las
respuestas, los códigos de error y las decisiones que no son obvias leyendo
las firmas: por qué la ingesta responde 202 y no 201, por qué los montos son
enteros, por qué `partial` no es un fallo.

Para verlo funcionando, **http://localhost:8080/api-console**: elegís tenant,
endpoint y parámetros, y ves la respuesta. Autocontenida, sin CDN ni
dependencias nuevas.

Tres cosas que conviene probar ahí:

1. **`GET /customers` con `q=gonzalez` y con `q=González`** — dan lo mismo. La
   búsqueda ignora acentos, porque un agente atendiendo un llamado escribe sin
   ellos.
2. **`POST /ingest/orders` con el cuerpo «Registros sucios»** — tres registros
   con distinta suciedad. Después mirá el lote en
   `/ingest/batches/{id}/rejects` para ver qué hizo el sistema con cada uno.
3. **El mismo POST dos veces** — el segundo devuelve `duplicate: 1`. Lo atrapa
   un índice único, no un `if`.

> La consola **no existe fuera del entorno local**: el controlador devuelve
> 404, no 403. Un 403 confirmaría que la ruta existe, y es una página que
> muestra credenciales — aunque sean las del seed, ya publicadas más arriba.

---

## 6. Cómo verificar el aislamiento entre tenants

Esta es la garantía central del sistema, así que se puede comprobar a mano,
contra el motor, sin pasar por la aplicación:

```bash
LUNA=$(docker compose exec -T postgres-shared psql -U postgres -d d4s_system -tAc \
  "select id from tenants where slug='luna'")

docker compose exec -T -e PGPASSWORD=app_secret postgres-shared \
  psql -U d4s_app -d d4s_shared -c "SET row_security = off; SELECT count(*) FROM customers;"
```

La respuesta es un error: `query would be affected by row-level security
policy for table "customers"`. El rol de la aplicación **ni siquiera puede
pedir** que se desactive el aislamiento, porque no tiene `BYPASSRLS`.

Tres propiedades que vale la pena mirar en
[`RowLevelSecurity.php`](platform/app/Domain/Tenancy/Database/RowLevelSecurity.php):

1. **`FORCE ROW LEVEL SECURITY`** — en Postgres el dueño de una tabla se
   saltea RLS por defecto; `FORCE` se lo aplica igual. Sumado a que el rol de
   la app no es dueño de nada, son dos candados sobre el mismo punto.
2. **Falla cerrada** — la política lee el contexto con `missing_ok`, así que
   una consulta sin tenant activo devuelve **cero filas, no todas**.
   Olvidarse de setear el contexto rompe la funcionalidad, nunca el
   aislamiento.
3. **`WITH CHECK`** — además de filtrar lecturas, prohíbe *escribir* una fila
   con el `tenant_id` de otro. Es la última línea contra el lote que trae
   registros ajenos: aunque la validación de la aplicación fallara, el INSERT
   lo rechaza el motor.

---

## 7. Qué hace el sistema con cada tipo de suciedad

Esta tabla sale de un comando, no de la memoria de nadie:

```bash
docker compose exec app php artisan d4s:dirt-table --tenant=luna --markdown
```

**Qué se genera y qué no**, porque la diferencia importa y decir "todo" sería
exagerar:

- **Las 11 filas de motivos de rechazo se generan desde el código.** Salen de
  recorrer `RejectReason`, el enum que el pipeline usa para clasificar cada
  registro que descarta: el texto de cada fila son sus métodos
  `describesDirt()` y `describesPolicy()`, y la columna **Observado** son los
  conteos reales de `ingestion_rejects`. Esas once no pueden quedar
  desactualizadas sin que el código cambie primero: agregar un motivo agrega
  una fila sola.
- **Las 7 filas restantes están escritas a mano**, en la constante
  `NON_REJECT_CASES` de `DirtTableCommand`. Son las que describen suciedad que
  **no termina en el rechazo de un registro** —un duplicado exacto, una
  actualización que llega desordenada, una venta con fecha vieja, un envío
  cortado a la mitad—, así que no hay un `RejectReason` del que derivarlas ni
  un conteo que mostrar: van con `—` en Observado. Redactarlas a mano fue la
  decisión; el costo es que esas siete sí pueden envejecer si la política
  cambia y nadie toca el comando. Están juntas y arriba de todo en el archivo
  para que se vean.

La columna **Observado** son los rechazos reales del dataset de `luna` con 2500
órdenes de carga inicial más 400 de flujo diario.

| Suciedad | Detalle | Qué hace el sistema | Observado |
|---|---|---|---:|
| Registro duplicado exacto | El mismo registro llega dos veces, idéntico byte por byte. | Índice único sobre (tenant_id, hash del contenido canonicalizado). Lo resuelve el motor de forma atómica: dos workers en paralelo no pueden insertarlo dos veces. Se cuenta como duplicado en el lote y no se reprocesa. | — |
| Duplicado parcial | Mismo identificador de origen, contenido distinto. | No es un duplicado sino una actualización. Upsert sobre (tenant_id, external_id) con guarda de versión: gana el evento con `source_updated_at` más nuevo, sin importar el orden de llegada. | — |
| Dato que llega tarde | Una orden de hace dos meses aparece en el envío de hoy. | Se acepta con normalidad. Se guardan por separado `placed_at` (cuándo ocurrió) y `received_at` (cuándo llegó), y el día afectado se marca en `aggregate_dirty_days` para que un trabajo recalcule sólo ese día. El retraso es medible en el campo `freshness` de las métricas. | — |
| Dos o más monedas | El mismo tenant vende en distintas monedas. | Se conserva el monto en su moneda original y se agrega el equivalente en la moneda de reporte, con la tasa del día de la orden congelada en la fila. Nunca se convierte al vuelo. | — |
| Fechas en distintos formatos y zonas | ISO con offset, epoch, formato latino, o sin zona horaria. | Se prueban varios formatos. Si la fecha no trae zona, se interpreta en la del TENANT y no en la del servidor: una venta a las 22:30 en Buenos Aires se cuenta ese día, no el siguiente. | — |
| Emails repetidos entre clientes distintos | Dos compradores comparten casilla. | Se acepta sin observaciones. El email es dato de contacto, no identidad: el índice sobre `email_normalized` NO es único, a propósito. Familias que comparten casilla y cuentas corporativas son casos reales. | — |
| Envío que se interrumpe a mitad de camino | Llegan menos registros de los que el emisor anunció. | El lote queda en estado `partial` con la cuenta exacta de lo recibido. Como la zona cruda es append-only y la normalización es idempotente, reenviar el lote completo es seguro: lo ya ingerido se cuenta como duplicado y no entra dos veces. | — |
| Falta un campo obligatorio | `missing_field` | Se descarta el registro y se anota el campo faltante. El resto del lote sigue. | 33 |
| Campo obligatorio presente pero vacío | `empty_field` | Cadena vacía y sólo-espacios se normalizan a nulo. Si el campo era obligatorio, se descarta el registro. | 20 |
| Campo con tipo o formato inesperado | `invalid_type` | Se intenta coerción tolerante (por ejemplo "1.234,56" a entero). Si no se puede, se descarta el registro. | 34 |
| El registro declara pertenecer a otro tenant | `foreign_tenant` | Se descarta el registro. Además, la política WITH CHECK de PostgreSQL rechazaría el INSERT aunque esta validación fallara. | 12 |
| El registro no trae identificador de origen | `missing_identifier` | Se descarta: sin identificador de origen no hay forma de deduplicar ni de actualizar después. | 13 |
| Monto negativo o no numérico | `invalid_amount` | Se descarta el registro. Un monto inválido contamina todos los agregados que lo toquen. | 17 |
| Moneda que no es un código ISO-4217 conocido | `unknown_currency` | Se descarta el registro. Convertirlo con una tasa inventada sería peor que no tenerlo. | 16 |
| Estado de orden que no se puede mapear | `unknown_status` | Se descarta el registro. Antes se intenta mapear sinónimos habituales (complete, canceled, refunded). | 12 |
| Fecha en un formato que no se puede interpretar | `malformed_date` | Se prueban varios formatos y zonas horarias. Si ninguno interpreta, se descarta el registro. | 22 |
| Email que no pasa validación | `email_invalid` | Se CONSERVA el cliente con el email marcado como inválido. Se anota el rechazo a nivel de campo, no de registro. | 54 |
| Actualización más vieja que el estado ya guardado | `stale_update` | Se ignora la actualización y se conserva el estado más nuevo. Queda registrada en el historial para poder investigarla. | 49 |

> Ninguna fila marca cero. Una política documentada que nunca se ejecuta es
> peor que no tenerla, así que el generador inyecta deliberadamente cada uno
> de estos casos y la tabla lo demuestra con conteos.

---

## 8. Tests

```bash
docker compose run --rm test
```

**295 tests, 681 aserciones.**

Corren contra PostgreSQL de verdad, en bases separadas de las de desarrollo.
No es una preferencia: la mitad de lo que se testea (Row-Level Security,
índices únicos parciales, JSONB, trigramas) no existe en SQLite. Testear
contra otro motor sería testear otro sistema.

Qué cubren, en orden de lo que más pesa:

| Suite | Qué prueba |
|---|---|
| `Feature/Tenancy` | Que sea imposible leer o escribir datos de otro tenant, incluso con SQL crudo y sin pasar por Eloquent |
| `Feature/Ingestion` | El ciclo completo: alcances de credencial, idempotencia, duplicados, guarda de versión, envío interrumpido |
| `Feature/Analytics` | Que una orden de hace dos meses corrija el pasado en vez de duplicarlo o ignorarlo |
| `Feature/Query` | Enmascarado de datos personales, filtros, y que la serie temporal salga de los agregados |
| `Unit/Ingestion` | Cada normalizador por separado: montos en las dos convenciones decimales, fechas en seis formatos, emails, hashes |
| `Unit/Emitter` | Que el dataset sea reproducible y que inyecte cada tipo de suciedad |
| `Feature/Dashboard` | La tabla de roles del enunciado convertida en tests, el login por organización, y que un admin no vea nada de otro tenant |
| `Feature/Outreach` | Deduplicación por dirección, idempotencia del envío, baja respetada entre el congelamiento y el envío, y pausa |
| `Unit/Outreach` | El DSL: qué rechaza y por qué, y que el SQL nunca interpole un valor del usuario |

### Análisis estático

```bash
cd platform && ./vendor/bin/phpstan analyse
```

**0 errores en nivel 5.** No es decoración: correrlo por primera vez —tarde,
ya casi terminado el proyecto— destapó cuatro problemas que ningún test podía
atrapar, porque no eran fallos de comportamiento sino contratos mal
declarados. El más concreto: el emisor leía su credencial con `env()`, que
devuelve `null` cuando la configuración está cacheada. Funcionaba sólo porque
el contenedor tenía el cacheo apagado.

Los otros tres: un método que devolvía una clave que su tipo no declaraba (y
por eso la ruta de pausa quedaba sin verificar), una guarda muerta en el
parser de fechas, y un middleware `verified` aplicado a todo el dashboard que
**no hacía nada** porque el modelo `User` no implementa `MustVerifyEmail`.

Los otros 115 hallazgos eran anotaciones faltantes en los modelos. No eran
bugs, pero eran el ruido que enterró a los cuatro de arriba: una herramienta
con 119 hallazgos es una herramienta que nadie mira.

### Iterar sin reconstruir la imagen

La imagen de `docker-compose.yml` hornea el código adentro, que es lo
correcto para evaluarla: es autocontenida y no depende del filesystem de
nadie. Para desarrollar, eso significa reconstruir en cada cambio.

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

Ese override monta `platform/` desde el disco. Los cambios se ven al
instante y la suite baja de minutos a segundos. Requiere haber corrido una
vez `composer install && npm ci && npm run build` dentro de `platform/`.

---

## 9. Dónde está cada cosa

```
README.md                      arranque, credenciales, limitaciones
DECISIONS.md                   35 decisiones: contexto → opciones → decisión → costo
docs/
  openapi.yaml                 contrato formal de la API (OpenAPI 3.1)
  parte-3-motor-de-acciones.md Parte 3: diseño del motor + recorte implementado
  diagramas/*.mmd              fuente Mermaid editable
docker-compose.yml             el sistema completo
docker-compose.dev.yml         override para desarrollar sin reconstruir
docker/                        Dockerfile, nginx, init de PostgreSQL
platform/                      la aplicación
  app/Domain/                  Tenancy · Ingestion · Sales · Analytics · Identity · Emitter
  app/Http/Api/V1/             API pública (autenticada por API key)
  app/Http/Dashboard/          dashboard (autenticado por sesión)
  database/migrations/system/  plano de control
  database/migrations/tenant/  plano de datos (corre en la compartida y en cada dedicada)
  tests/                       295 tests
```

Las dos superficies HTTP están separadas a propósito: la API y el dashboard
tienen amenazas, autenticación y ritmo de cambio distintos. Comparten el
núcleo de dominio, no los controladores.

---

## 10. Arquitectura en un minuto

```
                      ┌──────────────────────────────┐
   emisor ── HTTP ──▶ │  API de ingesta   (API key)  │
   (sin acceso a DB)  │  API de consulta  (API key)  │
                      │  Dashboard        (sesión)   │
                      └──────────────┬───────────────┘
                                     │
                    ┌────────────────┴────────────────┐
                    ▼                                 ▼
        ┌───────────────────────┐         ┌───────────────────────┐
        │  PLANO DE CONTROL     │         │  PLANO DE DATOS       │
        │  d4s_system           │         │                       │
        │                       │         │  d4s_shared      (RLS)│
        │  tenants              │         │   ├─ luna             │
        │  api_keys             │         │   └─ andes            │
        │  users                │         │                       │
        │                       │         │  d4s_tenant_mega      │
        │  Único y compartido.  │         │   └─ mega  (dedicada) │
        └───────────────────────┘         └───────────────────────┘
```

**Por qué el plano de control no puede estar aislado.** Cuando llega un
request con una API key todavía no se sabe a qué base ir: primero hay que
resolver la key para averiguar el tenant. Si las keys vivieran dentro de la
base del tenant, haría falta conocer el tenant para poder averiguar el
tenant. Por eso el registro es único, y por eso es el único lugar sin RLS.

**Por qué conviven dos estrategias.** El default es compartido porque es lo
que escala a miles de retailers; la base propia existe para el tenant que la
pide por contrato o cuyo volumen justifica dejar de competir por recursos.
`TenantConnectionResolver` es el único lugar del sistema que distingue una de
otra: el código de dominio nunca pregunta cuál está en juego. El razonamiento
completo, con los números que descartaron base-por-tenant como default, está
en [D-01](DECISIONS.md#d-01--multi-tenancy-híbrido-con-estrategia-por-tenant).

Todas las decisiones, con sus alternativas descartadas y sus costos, están en
**[DECISIONS.md](DECISIONS.md)**.

---

## 11. Limitaciones conocidas

Sección de lectura obligatoria, según el enunciado. Es honesta.

- **Sólo se normalizan órdenes.** Los lotes de `customers` y `products`
  sueltos quedan guardados en la zona cruda pero no se vuelcan al modelo, y
  el endpoint de estado los reporta como **rechazados** con motivo
  `unsupported_entity`. Los retailers mandan la venta completa con su
  comprador y sus renglones anidados, así que ese camino cubre el caso real;
  los otros dos quedan para cuando haga falta corregir catálogo sin reenviar
  ventas.

  Contarlos como aceptados —que es lo que hacía antes— le decía al que integra
  algo más fuerte de lo que pasó: «aceptado» significa que el registro llegó a
  la zona curada, y esos no llegan. La versión completa tendría un tercer
  desenlace (`stored_only`) que distinga «guardado sin procesar» de «no lo
  soporto»; hoy se elige el rechazo porque miente menos que la alternativa.
- **El límite de tasa de la API es por credencial, no una cuota por tenant.**
  `/api/v1/*` sí tiene techo: 300 peticiones por minuto en ingesta y 120 en
  consulta, contadas por credencial presentada y aplicadas **antes** de
  autenticar, así que también alcanzan a quien prueba claves al azar
  (`ApiRateLimitServiceProvider`, `config/d4s.php`). Lo que no hay es una
  cuota de **recursos** por tenant: un integrador con dos credenciales
  válidas tiene el doble de cupo, y el aislamiento de I/O contra el resto del
  cluster sigue dependiendo de que nadie se pase. El aislamiento de **datos**
  lo garantiza el motor; el de recursos, no.
- **La red de seguridad del recómputo corre cada cinco minutos, no al
  instante.** `routes/console.php` redespacha `RecomputeDirtyAggregates` para
  todos los tenants activos, y el compose levanta un servicio `scheduler`
  (`php artisan schedule:work`) que lo ejecuta. El camino feliz sigue siendo
  que la ingesta encole el recómputo al terminar cada lote; esto cubre el
  camino infeliz —un worker que muere después de marcar los días sucios, un
  job que agota reintentos—, con un peor caso de cinco minutos de desfase.
  `freshness` en cada respuesta de métricas lo hace visible mientras tanto.
- **Las tasas de cambio son una tabla fija.** En producción vendrían de un
  proveedor de tasas históricas, con su propio manejo de días sin cotización.
  Lo que **no** es una simplificación es el diseño: la tasa se resuelve con
  la fecha de la orden y queda guardada en la fila, así que el número es
  reproducible y auditable.
- **La validación de email exige ASCII.** Rechaza direcciones
  internacionalizadas, igual que `filter_var` y que SMTP sin la extensión
  SMTPUTF8. El costo del falso positivo es acotado: el comprador se conserva
  con el email marcado como inválido, no se pierde.
- **Los usuarios del dashboard no están protegidos por RLS.** Viven en el
  plano de control, que debe ser consultable antes de conocer el tenant. Un
  fallo en el scoping de la aplicación podría exponer el listado de empleados
  de otro tenant. El dato personal de compradores está todo en el plano de
  datos, que sí está protegido por el motor.
- **La zona cruda no tiene política de retención.** Guardar el payload
  original de millones de órdenes crece sin techo. Está identificado, no
  resuelto.
- **La paginación es por cursor, no por página.** Con cientos de miles de
  filas, `OFFSET 50000` obliga al motor a contar y descartar cincuenta mil
  filas antes de devolver nada. El costo es que no hay "ir a la página 7",
  que en una pantalla de soporte no le sirve a nadie.
- **Nada impide dejar una organización sin administradores.** Un
  administrador no puede desactivarse ni cambiarse el rol a sí mismo, pero sí
  puede bajar al último otro administrador. La regla completa exige contar
  administradores activos dentro de una transacción para evitar la carrera de
  dos bajas simultáneas; el caso propio cubre el riesgo más frecuente.
- **La pantalla de estadísticas carga todo el rango de una vez.** Con cuatro
  meses son ~120 puntos y responde bien. Con dos años de historia diaria
  convendría que la serie se pagine o que la granularidad por defecto suba a
  semana según el tamaño del rango.
- **Migrar los tenants dedicados es un recorrido secuencial.** Con uno es
  trivial; si esa porción creciera haría falta un orquestador con control de
  versión por tenant. Es una frontera conocida, no un descubrimiento futuro.
- **Las credenciales de base están en el compose, en claro.** Es un entorno
  de evaluación. La tabla de tenants ya está diseñada para un gestor de
  secretos: guarda el nombre lógico de la conexión, nunca un DSN.
- **`APP_DEBUG` está en `false`** también en local, a propósito: la API
  recibe input no confiable y un stack trace filtra estructura interna.

---

## 12. Qué haría distinto con otra semana

Es una de las preguntas de la defensa, así que va escrita y no improvisada.
Ordenado por lo que más cambiaría el resultado, no por lo que más me gustaría
hacer.

**Correr el análisis estático el primer día, no el último.** Larastan se
instaló al empezar y se corrió casi al final: 119 hallazgos, cuatro de ellos
problemas reales que ningún test podía atrapar porque no eran fallos de
comportamiento sino contratos mal declarados. Estuvieron ahí todo el tiempo,
enterrados bajo 115 anotaciones faltantes. El error no fue no tener la
herramienta: fue tenerla en `composer.json` sugiriendo un rigor que no se
ejercía. Está en [D-30](DECISIONS.md#d-30--el-análisis-estático-es-parte-de-la-entrega-no-un-adorno),
con el error de proceso incluido.

**Revisar la seguridad de lo que agrego, no sólo de lo que diseño.** El
aislamiento entre tenants se diseñó con cuidado desde el primer día y aguanta
verificación contra el motor. Y sin embargo la entrega llegó a tener cuatro
agujeros: una consola de evaluación agregada al final que servía API keys sin
sesión ([D-31](DECISIONS.md#d-31--la-consola-de-api-vive-detrás-del-control-de-roles-no-de-una-variable-de-entorno)),
el tablero de Horizon abierto ([D-32](DECISIONS.md#d-32--el-tablero-de-horizon-está-cerrado-para-todos)),
y dos restos de andamiaje de Breeze que asumían un producto con autoservicio
([D-33](DECISIONS.md#d-33--la-recuperación-de-contraseña-está-acotada-al-tenant),
[D-34](DECISIONS.md#d-34--no-se-puede-borrar-la-propia-cuenta)). Ninguno estaba
en el diseño; los tres llegaron por el costado. La conclusión no es «revisar
más»: es que **todo lo que se agrega tarde y todo lo que viene de un andamiaje
merece la misma lectura que el núcleo**, porque es donde la atención ya no
está.

**Un plano de operador.** Hoy todo usuario pertenece a una organización, así
que no hay nadie con derecho a mirar la cola compartida, y el tablero de
Horizon quedó cerrado para todos. La respuesta correcta no es una bandera de
depuración: es una identidad de operador con su propio dominio, su propia
autenticación y su propia auditoría.

**Cuota de recursos por tenant, no sólo límite por credencial.** El
aislamiento de datos lo garantiza el motor. El de recursos hoy depende de que
nadie se pase: dos credenciales del mismo retailer tienen el doble de cupo, y
un tenant ruidoso compite por I/O con los demás. Colas separadas por tenant en
Horizon y una cuota en el plano de control.

**Retención en la zona cruda.** Es la tabla que crece sin techo y la única sin
política. Particionado por mes y archivado a almacenamiento frío, con la
salvedad de que la trazabilidad («de dónde salió este dato») depende
justamente de esa zona: la política tiene que decidirse con esa pregunta en la
mano, no por costo.

**El modo por reglas del motor de acciones.** Es lo único del enunciado que
quedó diseñado y no implementado. El compilador rechaza `or`, `not`,
`purchased` y `last_order` **por nombre**, no los ignora — un motor que acepta
un `or` y lo compila mal es peor que uno que dice «todavía no».

**Y una cosa que no cambiaría.** La decisión de que la ingesta sea el único
punto de entrada, con el emisor corriendo en un contenedor que literalmente no
tiene credenciales de base de datos, es lo que hizo que la regla no dependiera
de la disciplina de nadie. Lo mismo con RLS forzado y el rol de aplicación sin
`BYPASSRLS`: las garantías que las hace cumplir el motor son las únicas que
siguen valiendo cuando alguien —yo, en este caso— agrega una pantalla a
último momento.

## 13. Uso de IA

Este proyecto se desarrolló con Claude Code como par de programación.

**Dónde se usó:** generación del andamiaje (Laravel, Docker, migraciones),
redacción de comentarios y documentación, y como interlocutor para discutir
alternativas de arquitectura.

**Qué se revisó y decidió a mano:** todas las decisiones de
[DECISIONS.md](DECISIONS.md). En particular, la estrategia de multi-tenancy
se resolvió discutiendo contra la propuesta inicial: la primera intuición fue
base-por-tenant por ser "más limpia", y se cambió a híbrido después de poner
sobre la mesa los números de conexiones, migraciones y catálogo que la vuelven
inviable a la escala objetivo. El diseño del modelo de datos, la elección de
RLS con separación de roles de Postgres, y qué se testea y qué no, son
decisiones tomadas y verificadas a mano.

**Qué se verificó y no se dio por bueno.** Todo lo que este README afirma se
comprobó ejecutándolo: el arranque en frío tras `down -v`, la matriz de roles
por HTTP contra la imagen de entrega, el aislamiento contra el motor con
`psql` y el rol de la aplicación, y los ocho diagramas de la Parte 3 con el
parser real de Mermaid. Nueve bugs aparecieron en el camino y están
corregidos con test de regresión — entre ellos un healthcheck de PostgreSQL
que respondía OK antes de que existieran los roles, y una carrera entre el
controlador y el trabajo de ingesta por el estado de un lote.
