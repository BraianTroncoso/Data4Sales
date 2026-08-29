# DECISIONS

Registro de decisiones de arquitectura. Cada una sigue el mismo formato:
**contexto** → **opciones consideradas** → **decisión** → **consecuencias**.

Las consecuencias incluyen las malas. Una decisión sin costo documentado es
una decisión que todavía no se entendió del todo.

---

## D-01 · Multi-tenancy: híbrido con estrategia por tenant

**Contexto.** La plataforma es B2B y apunta a cientos o miles de retailers.
Cada uno necesita que sus datos estén aislados de los demás, y alguno va a
pedir aislamiento físico por contrato. El enunciado además pregunta, en la
defensa, cuánto trabajo cuesta darle a un tenant su propia instancia.

**Opciones consideradas.**

*Esquema compartido con `tenant_id`, sin más.* Es lo más simple y lo que
escala mejor en operación: una base, un juego de migraciones, un pool de
conexiones. El problema es la garantía: si el aislamiento vive en un global
scope de Eloquent, cualquier `withoutGlobalScopes()` o `DB::raw()` lo
esquiva, y no hay un punto del sistema que se pueda señalar como infranqueable.

*Una base por tenant.* Aislamiento físico total y el modelo mental más
simple de explicar. Se descartó como estrategia por defecto por tres costos
que aparecen justo a la escala objetivo: las conexiones (cada base necesita
su propio pool, y con mil tenants y un mínimo de 5 conexiones son 5.000
conexiones contra un motor que reserva megabytes por cada una); las
migraciones (una migración de 2 segundos sobre mil bases son 35 minutos en
serie, y un fallo a mitad deja la flota en dos versiones de esquema); y el
catálogo de PostgreSQL, que se degrada con miles de bases en una instancia,
obligando a sharding — es decir, a construir igual la capa de ruteo que esta
estrategia pretendía evitar. Es la estrategia correcta para pocos clientes
muy grandes con aislamiento contractual, no para muchos retailers.

*Un esquema de Postgres por tenant.* Punto intermedio, pero hereda el
problema de migraciones sin resolver el de conexiones, y complica el
tooling. No aporta sobre las otras dos.

**Decisión.** Esquema compartido como estrategia por defecto, aislado por
Row-Level Security de PostgreSQL, **más una puerta de salida real**: la
tabla `tenants` lleva una columna `isolation_strategy`, y
`TenantConnectionResolver` es el único punto del sistema que traduce un
tenant a una conexión. Un tenant `dedicated` corre sobre su propia base, con
el esquema idéntico, y el código de dominio no distingue una de otra.

**Consecuencias.**

- El aislamiento por defecto lo garantiza el motor, no la aplicación (ver D-02).
- Promover un tenant a base propia es mover datos, nunca transformar
  estructura, porque el esquema dedicado conserva la columna `tenant_id` y
  las políticas de RLS aunque haya un solo tenant adentro.
- Aparece un plano de control que no puede estar aislado (ver D-03).
- El costo real es que hay dos caminos posibles en el resolver, y cualquier
  código que obtenga una conexión salteándolo rompe la abstracción. Se
  mitiga concentrando el acceso al plano de datos en `TenantContext`.
- Migrar sigue siendo un recorrido sobre N bases para la porción dedicada.
  Con un puñado de tenants dedicados es trivial; si esa porción creciera,
  haría falta un orquestador con control de versión por tenant. Es una
  frontera conocida, no un descubrimiento futuro.

---

## D-02 · El aislamiento vive en el motor: RLS y separación de roles

**Contexto.** Con esquema compartido, la pregunta *"mostrame el punto exacto
donde es imposible leer datos de otro tenant"* tiene que tener una respuesta
señalable, no una promesa.

**Opciones consideradas.**

*Global scope de Eloquent.* Cómodo y suficiente para el 95% del código,
pero es una convención: se desactiva con una llamada, y no cubre consultas
crudas ni nada que no pase por Eloquent.

*Repositorios que reciben el tenant obligatoriamente.* Mejor disciplina,
mismo problema de fondo: sigue siendo la aplicación protegiéndose a sí misma.

*Row-Level Security de PostgreSQL.* El motor filtra por fila, debajo de
todo lo que la aplicación pueda hacer.

**Decisión.** Las tres capas, en profundidad, con RLS como la única que da
garantía:

1. Middleware que resuelve el tenant y publica `app.tenant_id` en la sesión
   de la conexión.
2. Scope de aplicación, por ergonomía y para fallar temprano.
3. Políticas de RLS sobre cada tabla del plano de datos.

Lo que hace que la tercera capa sea real es la separación de roles de
Postgres: `d4s_owner` es dueño del esquema y solo lo usa el contenedor de
inicialización; `d4s_app` es el rol del runtime, no es dueño de ninguna
tabla, y no tiene `BYPASSRLS`. En Postgres el dueño de una tabla se saltea
RLS por defecto, así que además cada tabla lleva `FORCE ROW LEVEL SECURITY`.
El contenedor de la aplicación literalmente no conoce la contraseña del rol
dueño.

**Consecuencias.**

- La respuesta a la pregunta de la defensa es un archivo:
  `platform/app/Domain/Tenancy/Database/RowLevelSecurity.php`. Para que
  fallara habría que conectar la app con un rol con `BYPASSRLS`.
- La política incluye `WITH CHECK`, así que también prohíbe *escribir* una
  fila con el `tenant_id` de otro. Es la última línea contra el lote que
  trae registros ajenos: aunque la validación de la aplicación fallara, el
  INSERT lo rechaza el motor.
- El modo de fallo es cerrado: la política lee el contexto con `missing_ok`,
  de modo que sin contexto una consulta devuelve **cero filas**, no todas.
  Olvidarse de setear el tenant rompe la funcionalidad, nunca el aislamiento.
- El costo es operativo: hay que setear el contexto en cada unidad de
  trabajo, incluidos los trabajos en cola, y hay que acordarse de limpiarlo
  en procesos largos. `TenantContext::run()` lo encapsula y restaura el
  estado previo incluso ante excepciones.
- Queda atado a PostgreSQL. Es un costo aceptado: el resto del diseño ya se
  apoya en JSONB, índices parciales y trigramas.

---

## D-03 · Un plano de control separado, y sin RLS

**Contexto.** Cuando llega un request con una API key todavía no se sabe a
qué base ir: primero hay que resolver la key para averiguar el tenant. Si
las keys vivieran dentro de la base del tenant, haría falta conocer el
tenant para poder averiguar el tenant.

**Opciones consideradas.** Replicar el registro de keys en cada base (obliga
a sincronizar y no resuelve el arranque); resolver el tenant por subdominio
o por un header aparte (traslada el problema al cliente y deja la
autenticación sin poder verificarse contra nada).

**Decisión.** Una base de control separada (`d4s_system`) con `tenants`,
`api_keys` y los usuarios del dashboard. Es única y compartida por diseño.
No lleva políticas de RLS, porque tiene que ser consultable *antes* de
conocer el tenant.

**Consecuencias.**

- El aislamiento del plano de control queda en manos de la aplicación. Se
  acepta porque su superficie es chica y no contiene datos personales de
  compradores: el PII que el enunciado marca como sensible vive todo en el
  plano de datos, que sí está protegido por el motor.
- Es un punto único de falla y un cuello de botella potencial. A escala se
  mitiga con réplicas de lectura y cacheo de la resolución de API key, que
  es el 100% del tráfico de esa base.
- **Limitación conocida:** los usuarios del dashboard no están protegidos
  por RLS. Un fallo en el scoping de la aplicación podría exponer el listado
  de usuarios de otro tenant (nombres y emails de empleados, no de
  compradores). Está en la lista de mejoras.

---

## D-04 · Dos zonas de datos: cruda y curada

**Contexto.** El dataset entra sucio a propósito: duplicados exactos y
parciales, datos que llegan tarde, campos faltantes o mal tipados, registros
de otro tenant, envíos que se cortan a la mitad.

**Opciones consideradas.**

*Validar y escribir directo a las tablas de negocio.* Menos almacenamiento y
menos piezas. Pero pierde el original: ante una discrepancia no hay contra
qué comparar, y un cambio en las reglas de limpieza no se puede reprocesar
sobre lo ya ingerido.

*Cola de mensajes como zona cruda.* Válido, pero el historial de una cola es
efímero y no es consultable con SQL. La trazabilidad quedaría en un sistema
distinto del que responde las preguntas.

**Decisión.** Zona cruda append-only (`ingestion_batches`, `raw_events`) con
el payload en JSONB tal cual llegó, más su hash; zona curada normalizada
(`customers`, `orders`, `order_items`, `products`); y una tabla de rechazos
con motivo tipado.

**Consecuencias.**

- La deduplicación exacta la garantiza un índice único sobre
  `(tenant_id, content_hash)`, es decir el motor y de forma atómica. No hay
  ventana de carrera entre consultar y escribir, ni siquiera con varios
  workers sobre el mismo lote.
- Un envío interrumpido deja el lote en `partial` con las cuentas exactas.
  Como la zona cruda es append-only y la normalización es idempotente,
  reintentar es seguro.
- Los motivos de rechazo son tipados, así que la tabla de suciedad del
  README es una consulta agrupada y no una promesa escrita a mano.
- El costo es almacenamiento duplicado, que en el tenant de millones de
  órdenes no es menor. Requiere una política de retención de la zona cruda
  (pendiente de implementar; anotado en limitaciones).

---

## D-05 · Idempotencia y datos que llegan tarde

**Contexto.** La ingesta recibe altas *y* actualizaciones, los reintentos son
normales, y una orden de hace dos meses puede aparecer en el envío de hoy.

**Decisión.** Tres mecanismos en capas distintas:

1. **Por lote:** el emisor manda una clave de idempotencia. Reenviar el
   mismo lote devuelve el lote original en vez de crear uno nuevo.
2. **Por evento:** índice único sobre el hash del contenido canonicalizado.
   El hash incluye el timestamp del evento de origen, para que dos estados
   distintos de la misma orden no colisionen entre sí.
3. **Por entidad:** upsert sobre `(tenant_id, external_id)` **con guarda de
   versión** — una orden se actualiza solo si el evento que llega es más
   nuevo que el estado guardado, comparando `source_updated_at` y no el
   orden de llegada.

**Consecuencias.**

- Sin la guarda de versión, un reintento desordenado devolvería una orden
  entregada al estado "pagada". Con ella, los eventos viejos se ignoran de
  forma explícita y quedan contabilizados.
- Se modelan tres tiempos distintos por orden: `placed_at` (cuándo ocurrió),
  `source_updated_at` (cuándo el origen generó ese estado) y `received_at`
  (cuándo nos llegó). La diferencia entre el primero y el tercero *es* la
  definición de dato tardío, y es medible.
- El costo es que "más nuevo" depende de un reloj ajeno. Si el sistema de
  origen manda timestamps inconsistentes, la guarda decide mal. Se mitiga
  registrando en el historial de estados toda transición ignorada, de modo
  que el caso sea investigable en vez de silencioso.

---

## D-06 · Agregados incrementales con marcado de días sucios

**Contexto.** La pantalla de estadísticas tiene que seguir siendo usable con
el tenant de millones de órdenes, y el enunciado pregunta explícitamente qué
queda mal cuando llega una orden de hace 60 días.

**Opciones consideradas.**

*Agregar en tiempo de consulta.* Siempre exacto, pero sumar millones de
filas por carga de página no escala por más índices que se agreguen.

*Vistas materializadas refrescadas por cron.* Simple de montar, pero
refrescar es recalcular todo el histórico: caro y sin relación con cuánto
cambió realmente.

**Decisión.** Tablas derivadas (`daily_sales_facts`, `customer_metrics`)
mantenidas de forma incremental y dirigida: cuando la ingesta toca una orden
marca el día de esa orden en `aggregate_dirty_days`, y un trabajo recomputa
únicamente los días marcados. El índice único parcial sobre los días sin
procesar hace que marcar mil veces el mismo día deje una sola fila.

**Consecuencias.**

- La respuesta a la orden de hace 60 días deja de ser vaga: queda mal ese
  día puntual, en esas dos tablas, durante lo que tarde el recómputo, y el
  retraso se mide mirando la fila pendiente más antigua.
- El dashboard es eventualmente consistente. Se acepta para inteligencia de
  negocio; la pantalla de soporte, que atiende un caso puntual, consulta las
  tablas curadas directamente y no pasa por agregados.
- Los puntajes RFM se calculan por quintiles **dentro de cada tenant**: un
  cliente valioso de una tienda de barrio no es comparable con uno de una
  cadena.

---

## D-07 · Dinero y tiempo

**Contexto.** Hay al menos dos monedas, fechas en varios formatos y zonas
horarias, y tres meses de historia que tienen que dar el mismo número cada
vez que se consultan.

**Decisión.**

- **Dinero en enteros**, siempre en la unidad mínima de la moneda
  (`bigint`), nunca coma flotante ni decimal.
- **La moneda no se convierte al vuelo.** Cada orden guarda su monto
  original con su moneda y, además, un monto en la moneda de reporte del
  tenant con la tasa del día de la orden ya aplicada y guardada.
- **Todo se almacena en UTC** (`timestamptz`), pero cada tenant declara su
  zona horaria y los reportes se agrupan por el **día del tenant**,
  precalculado en la columna `placed_on`.

**Consecuencias.**

- Sumar millones de órdenes no acumula error: 0.10 no es representable en
  binario, y ese error, multiplicado, produce un revenue que no cierra
  contra el del retailer.
- Si se convirtiera con la tasa de hoy, el revenue de marzo cambiaría cada
  vez que alguien abre el dashboard y ningún reporte sería reproducible.
  Guardar la tasa usada hace el número auditable.
- Precalcular el día del tenant evita aplicar una función sobre millones de
  filas en cada consulta, que anularía el índice.
- El costo: la tasa de cambio es un dato de entrada más, con su propia
  frescura y sus propios huecos. En esta entrega se usa una tabla de tasas
  fija por día; en producción vendría de un proveedor con su propio
  tratamiento de faltantes.

---

## D-08 · El email no identifica a un cliente

**Contexto.** El enunciado exige emails inválidos y emails repetidos entre
clientes distintos.

**Decisión.** La identidad de un cliente es `(tenant_id, external_id)`, la
clave natural del retailer. El email es un atributo de contacto: se indexa
para búsqueda pero **no es único**, y lleva un estado explícito
(`valid` / `invalid` / `missing`). Un email inválido no descarta al cliente:
se guarda el cliente, se marca el estado y se anota el rechazo del campo.

**Consecuencias.**

- Se soportan los casos reales que producen emails repetidos: familias que
  comparten casilla, cuentas corporativas, la caja de una tienda.
- Perder un comprador entero porque su mail tiene un espacio de más sería
  peor que quedarse sin ese mail.
- El estado del email es lo que después permite que el motor de la Parte 3
  excluya destinatarios no contactables sin tener que revalidar en el momento
  del envío.
- **Los usuarios del dashboard sí** llevan índice único `(tenant_id, email)`:
  ahí el email es credencial de acceso, no dato de contacto. La consecuencia
  es que el login pide el slug del tenant además del email, como hace Slack
  con el workspace.

---

## D-09 · API keys hasheadas con SHA-256, no con bcrypt

**Contexto.** El acceso a las APIs es por API key por tenant, y esa
resolución ocurre en cada request.

**Decisión.** Se guarda SHA-256 del secreto, con índice único, y el secreto
se genera con 256 bits de entropía.

**Consecuencias.**

- Va a contramano del reflejo habitual, por dos razones. El costo de bcrypt
  existe para frenar fuerza bruta contra secretos que eligió un humano;
  contra un secreto aleatorio de 256 bits no compra nada, porque el espacio
  de búsqueda ya es inalcanzable. Y bcrypt no se puede indexar: autenticar
  con él implicaría traer todas las keys y compararlas de a una en cada
  request.
- La autenticación es un lookup por índice único, en tiempo constante, sin
  importar cuántos tenants haya.
- Se guarda un prefijo en claro para poder identificar y rastrear una key en
  la interfaz y en los logs sin exponer nunca el secreto.
- El secreto existe en claro una sola vez, al generarse. Si se pierde, se
  revoca y se emite otro. Esa imposibilidad de recuperarlo es la propiedad
  buscada.

---

## D-10 · Ingesta asíncrona

**Contexto.** El tenant grande envía millones de registros, y uno de los
casos obligatorios es un envío que se corta a la mitad.

**Decisión.** La API de ingesta valida el sobre, persiste el lote crudo,
encola el procesamiento y responde **202 con el identificador del lote**.
El estado se consulta por un endpoint aparte. El procesamiento corre en
Horizon, por trozos.

**Consecuencias.**

- Un request sincrónico no aguantaría el volumen y convertiría cualquier
  corte de red en pérdida de datos.
- El emisor recibe una respuesta rápida y sabe dónde mirar. La visibilidad
  del progreso deja de depender de que la conexión siga abierta.
- El costo es que "ingerido" ya no es lo mismo que "aceptado": entre las dos
  cosas hay una ventana. Por eso el estado del lote incluye las cuentas de
  aceptados, rechazados y duplicados, y el endpoint de estado es parte del
  contrato y no un extra.

---

## D-11 · Sin registro público

**Contexto.** Breeze trae registro abierto por defecto.

**Decisión.** Se eliminó. Los usuarios los crea un administrador dentro de
su propio tenant.

**Consecuencias.** En una plataforma B2B multi-tenant, un formulario de alta
pública es una vía directa a crear cuentas sin tenant o adivinables. Quitarlo
es más barato que custodiarlo. La gestión de usuarios de la Parte 2 es el
único camino de alta.

---

## D-12 · Stack

**Contexto.** El enunciado deja el stack libre y pide justificar lo poco
convencional.

**Decisión.** PHP 8.4 · Laravel 11 · PostgreSQL 16 · Redis + Horizon ·
Vue 3 + Inertia + Tailwind · Pest. Todo en un monolito con dos superficies
HTTP separadas: la API pública (autenticada por API key) y el dashboard
(autenticado por sesión), sobre un núcleo de dominio compartido.

**Consecuencias.**

- Nada acá es exótico, y esa es la intención: el criterio evaluable está en
  el diseño, no en la elección de herramienta.
- PostgreSQL no es intercambiable en este diseño: RLS, JSONB, índices
  parciales y trigramas son todos específicos. Es una dependencia asumida.
- Un monolito con dos superficies HTTP mantiene el límite entre componentes
  visible y hace que separarlos en servicios, si hiciera falta, sea un
  movimiento acotado — sin pagar hoy el costo de operar dos despliegues.

---

## D-13 · La ingesta sólo normaliza órdenes

**Contexto.** El enunciado pide como entidades mínimas clientes, órdenes,
ítems de orden y productos. La API acepta lotes de tres tipos: `orders`,
`customers` y `products`.

**Opciones consideradas.** Un pipeline de normalización por cada tipo de
entidad, con su propio juego de reglas y sus propios tests; o un solo
pipeline sobre órdenes, con el comprador y los renglones anidados.

**Decisión.** Sólo se normalizan órdenes. Traen su comprador y sus renglones
adentro, que es la forma en que los retailers mandan una venta. Los lotes de
clientes y productos sueltos se aceptan, se persisten en la zona cruda y se
cuentan como aceptados, pero no se vuelcan al modelo.

**Consecuencias.**

- Cubre el caso real con un solo camino bien testeado en vez de tres a medias.
- No se puede corregir el catálogo ni el padrón sin reenviar ventas. Es el
  caso que queda afuera, y está declarado en las limitaciones del README.
- La zona cruda ya guarda esos lotes, así que agregar el pipeline faltante no
  requiere pedirle nada al retailer: los datos ya están.

---

## D-14 · El dinero cruza monedas con la tasa de la orden, no la de hoy

**Contexto.** Cada tenant tiene una moneda de reporte y vende en varias.

**Decisión.** `ExchangeRateProvider` resuelve la tasa con la fecha de la
orden y el resultado se guarda en la fila (`fx_rate`), junto al monto ya
convertido. En esta entrega las tasas son una tabla fija.

**Consecuencias.**

- El revenue de marzo da el mismo número cada vez que se consulta. Convertir
  al vuelo con la tasa de hoy haría que cambiara solo, y ningún reporte sería
  reproducible ni auditable.
- Los agregados por producto usan **la misma tasa congelada de la orden**, no
  una nueva. Si recalcularan, el total por producto no cerraría contra el
  total de ventas del mismo día — la clase de discrepancia que aparece en una
  reunión y nadie puede explicar.
- La tabla fija es la parte reemplazable. La firma del proveedor ya recibe la
  fecha, así que cambiarlo por uno real no toca a ninguno de sus llamadores.

---

## D-15 · La regla de segmentación vive en PHP y el SQL se genera desde ella

**Contexto.** Los puntajes RFM son quintiles: para saber si un cliente está
en el 20% que más gasta hay que mirar a todos los demás. Eso obliga a
calcularlos en la base con funciones de ventana. Pero la regla que traduce
tres puntajes en un segmento es lógica de negocio.

**Opciones consideradas.** Escribir la regla dos veces, una en PHP para el
dominio y otra como `CASE` en SQL; o traer todos los clientes a memoria para
segmentarlos en PHP.

**Decisión.** La regla vive una sola vez, en `RfmSegment::fromScores()`. De
ahí se **genera** una tabla de 125 combinaciones que el SQL consume como
`VALUES` y resuelve con un hash join.

**Consecuencias.**

- No pueden divergir. Duplicar la regla a mano es la clase de inconsistencia
  que no se nota hasta que alguien pregunta por qué el dashboard y una
  campaña cuentan distinto el mismo cliente.
- No hay que traer millones de filas a memoria para segmentarlas.
- 125 filas generadas son más código del que un `CASE` escrito a mano
  necesitaría. Se acepta: es código generado, no código a mantener.

---

## D-16 · El envío incompleto lo detecta el trabajo, no el controlador

**Contexto.** Uno de los casos obligatorios es un envío que se corta a la
mitad. Desde el servidor, "mandaron pocos" y "se cortó" se ven idénticos, así
que el emisor declara cuántos registros anuncia (`expected_count`).

**Decisión.** El controlador guarda el número anunciado y nada más. El
estado final del lote lo decide **únicamente** el trabajo que lo procesa, al
terminar.

**Consecuencias.**

- La primera versión marcaba el lote como `partial` en el controlador, y eso
  competía con el trabajo que lo estaba procesando: el último en escribir
  ganaba por azar. El síntoma era un emisor que reportaba cero aceptados
  porque dejaba de esperar antes de tiempo. Concentrar la decisión en un solo
  punto elimina la carrera en vez de mitigarla.
- `partial` no es un fallo: los registros que sí llegaron se procesan
  igual. Distinguirlo de `completed` le dice al retailer que tiene que
  reenviar; distinguirlo de `failed` evita que descarte lo que ya entró.
- Reenviar el lote completo es seguro: lo ya ingerido se cuenta como
  duplicado por el índice de hash de contenido y no entra dos veces.

---

## D-17 · Sin capa de compatibilidad para offset en la paginación

**Contexto.** El listado de clientes del tenant grande tiene cientos de miles
de filas, y la pantalla de soporte lo pagina.

**Decisión.** Paginación por cursor, no por offset.

**Consecuencias.**

- `OFFSET 50000` obliga al motor a contar y descartar cincuenta mil filas
  antes de devolver nada; el cursor salta directo por el índice.
- El ordenamiento siempre desempata por clave primaria. Sin un orden total,
  la paginación por cursor puede repetir u omitir filas cuando varias
  comparten el mismo valor de ordenamiento — un bug silencioso y difícil de
  reproducir.
- No hay "ir a la página 7" ni total de resultados. En una pantalla donde
  alguien busca un caso puntual, ninguna de las dos cosas le sirve.

---

## D-18 · El tenant va dentro de las credenciales, no en un filtro posterior

**Contexto.** El login del dashboard tiene que autenticar dentro de una
organización. El índice único de `users` es `(tenant_id, email)`, así que el
email solo no identifica a nadie.

**Opciones consideradas.**

*Autenticar por email y después verificar el tenant.* Es lo que sale
naturalmente al agregar multi-tenancy sobre un login que ya existía. El
problema es que existe un instante —entre el `Auth::attempt` y la
verificación— en el que un usuario del tenant equivocado está autenticado. Si
algo entre medio lee la sesión, ya es tarde.

*Resolver el tenant por subdominio.* Elegante en producción, pero obliga a
DNS con comodín y certificados, y el enunciado pide que todo levante con un
comando en la máquina del evaluador.

**Decisión.** El `tenant_id` viaja **dentro** del array de credenciales de
`Auth::attempt`, junto al email y la contraseña. Lo mismo con `is_active`.

**Consecuencias.**

- No hay ventana: un usuario de otro tenant, o desactivado, sencillamente no
  puede autenticarse. No es que se autentique y después lo echemos.
- El formulario pide tres campos. Es más fricción que dos, y es la fricción
  correcta: en B2B el tenant es parte de la identidad, no un detalle a
  inferir. Slack pide el workspace por lo mismo.
- Un tenant inexistente y una contraseña incorrecta devuelven **el mismo
  mensaje**. Distinguirlos le diría a quien prueba credenciales qué
  organizaciones existen en la plataforma.
- El límite de intentos se calcula por `(tenant, email, IP)`. Si fuera sólo
  por email, un ataque contra una organización bloquearía el acceso legítimo
  de otra que comparte el correo.

---

## D-19 · Las rutas declaran capacidades, no roles

**Contexto.** El enunciado define tres roles con una tabla de acceso, y exige
que un usuario no pueda acceder a lo que no le corresponde "ni desde la
interfaz ni por fuera de ella".

**Opciones consideradas.** Un middleware `role:analyst,admin` en cada ruta; o
un paquete de permisos con tablas de roles y permisos configurables.

**Decisión.** Middleware por **capacidad**: `can.role:statistics`,
`can.role:customers`, `can.role:users`. La regla de qué rol tiene cada
capacidad vive en un solo lugar, el enum `UserRole`.

**Consecuencias.**

- Cuando un cuarto rol tenga que ver estadísticas, se toca el enum y no las
  rutas que lo usan.
- Las mismas capacidades se comparten al frontend ya resueltas
  (`auth.can.statistics`), en vez del rol crudo. Si el menú recibiera el rol,
  tendría que reimplementar la regla y las dos copias divergirían.
- **Ocultar el enlace no es control de acceso.** El menú refleja lo mismo que
  el servidor va a permitir, pero quien escriba la URL a mano recibe un 403.
  Hay tests que piden las URLs directamente, sin pasar por ninguna interfaz.
- No se usó un paquete de permisos: para tres roles fijos definidos por el
  enunciado, una tabla de roles configurable sería complejidad sin caso de
  uso. Si mañana los roles fueran por tenant, esto se convierte en tabla.

---

## D-20 · El listado de clientes enmascara el contacto; el detalle no

**Contexto.** La pantalla de clientes expone datos personales de compradores
reales, y el enunciado dice explícitamente que las decisiones al respecto son
parte de lo que se evalúa.

**Decisión.** El **listado** devuelve el email enmascarado
(`ma***ez@example.test`) y no devuelve el teléfono. El **detalle** devuelve
el contacto completo.

**Consecuencias.**

- Un listado se pagina, se exporta y se copia; un detalle se abre de a uno
  para atender un caso concreto. Que el listado no lleve contacto completo
  hace que una exportación accidental de mil filas no sea una fuga de mil
  contactos.
- Ocultarlo también en el detalle sería seguridad de utilería: es la pantalla
  donde un agente tiene que responderle a esa persona.
- El detalle muestra el email **tal como lo mandó el retailer**, no el
  normalizado. Si está mal escrito, el agente necesita ver el error para
  poder corregirlo con el cliente.
- El enmascarado es una decisión de presentación, no de almacenamiento: el
  dato completo está en la base y protegido por Row-Level Security. No
  reemplaza al aislamiento, lo complementa.

---

## D-21 · Un administrador no puede cambiarse el rol ni desactivarse

**Contexto.** La pantalla de gestión de usuarios permite editar roles y dar
de baja.

**Decisión.** Las dos operaciones se rechazan cuando el objetivo es el propio
usuario, en el servidor, y los controles aparecen deshabilitados en la
interfaz con la explicación.

**Consecuencias.**

- Evita que el único administrador de una organización se deje afuera. Ese
  escenario no tiene salida desde la aplicación: hay que tocar la base a
  mano, y en producción significa un ticket de soporte y un acceso
  privilegiado que idealmente nadie tiene.
- No se implementó "impedir que quede la organización sin administradores",
  que sería la regla completa. Es más difícil de lo que parece (hay que
  contar administradores activos dentro de una transacción para evitar la
  carrera de dos bajas simultáneas) y el caso propio cubre el 90% del riesgo
  real. Queda declarado como limitación.

---

## D-22 · El compilador de condiciones rechaza por nombre lo que no implementa

**Contexto.** El DSL del motor de acciones está diseñado completo (`and`, `or`,
`not`, agregados sobre órdenes, afinidad producto-cliente, ventanas relativas
y absolutas), pero el recorte vertical implementa un subconjunto.

**Opciones consideradas.**

*Compilar lo que se entiende e ignorar el resto.* Es lo que sale
naturalmente cuando el parser recorre un árbol. Un `or` ignorado dentro de un
`and` produce una audiencia **más grande** que la pedida, y el síntoma es
gente recibiendo correos que no debería. Lo descubre el destinatario, no
nosotros.

*Compilar `or` a medias con `LEFT JOIN` y booleano sobre columnas nulas.* Es
la implementación correcta, y es más código del que entra en el recorte.
Medio hacerla produce audiencias silenciosamente equivocadas.

**Decisión.** `ConditionParser` valida el árbol **entero antes de compilar** y
lanza `InvalidCondition` nombrando el operador no soportado, con un mensaje
que apunta al documento de diseño.

**Consecuencias.**

- Una condición que usa `or` falla al definirse, no al enviarse. El error
  cuesta un mensaje de error; el silencio cuesta una lista de destinatarios.
- La lista blanca de campos (`FieldRegistry`) cumple dos funciones a la vez:
  impide que el nombre de una columna venga del JSON de un usuario —eso es
  inyección SQL, porque un identificador no se puede parametrizar— y evita
  que una columna nueva de `customers` se vuelva segmentable sin que nadie lo
  decida.
- El costo es que el usuario ve un error en vez de un resultado. Es el costo
  correcto.

---

## D-23 · Ninguna hoja agregada se compila como subconsulta correlacionada

**Contexto.** Una condición como "compró más de tres veces en los últimos 90
días con ticket promedio sobre X" es una agregación sobre las órdenes de cada
cliente.

**Opciones consideradas.** Una subconsulta correlacionada por hoja
(`WHERE (SELECT count(*) FROM orders o WHERE o.customer_id = c.id ...) > 3`),
que es lo que sale de traducir la condición literalmente.

**Decisión.** Cada hoja agregada se compila como **un CTE con una sola pasada
agrupada por cliente**, y el árbol booleano se reconstruye uniendo esos CTEs.
Bajo un `and` sin negación toda hoja es obligatoria, así que el filtro se
empuja al `HAVING` del propio CTE y la unión es `INNER JOIN`.

**Consecuencias.**

- Una subconsulta correlacionada se ejecuta **una vez por cliente**. Con el
  tenant de 150.000 clientes son 150.000 ejecuciones. El CTE son dos
  recorridos de índice y un hash join, sin importar cuántos clientes haya.
- Empujar el filtro al `HAVING` descarta lo más caro lo antes posible: al
  join llegan sólo los clientes que ya cumplen.
- Si el recorte soportara `or`, esas hojas dejarían de ser obligatorias y
  habría que pasar a `LEFT JOIN` con el booleano evaluado sobre columnas
  nulas. Está diseñado y no implementado, y por eso `or` se rechaza (D-22).
- **Bug real que esto costó:** los bindings se acumulaban en orden de
  procesamiento de las hojas, pero los CTEs aparecen primero en el texto del
  SQL y PostgreSQL los liga por posición. El resultado era una consulta
  sintácticamente válida que comparaba el `tenant_id` contra un umbral de
  dinero. No fallaba: devolvía resultados incorrectos. Hay un test que asierta
  el orden exacto de los bindings, precisamente porque los otros veintiuno
  pasaban con el bug adentro.

---

## D-24 · `claimed` e `in_flight` son estados distintos

**Contexto.** Entre "llamamos al proveedor de correo" y "sabemos qué
respondió" hay una ventana en la que el proceso puede morir.

**Decisión.** Una intención de envío pasa por `queued` → `claimed` →
`in_flight` → `sent`. Los dos del medio no se colapsan.

**Consecuencias.**

- `claimed` significa "un worker la tomó pero todavía no llamó al proveedor".
  Si el proceso muere ahí, devolverla a la cola es seguro: nadie recibió nada.
- `in_flight` significa "ya se llamó y no sabemos cómo terminó". Reintentarla
  puede duplicar, así que el recolector la marca `unresolved` para que una
  persona la mire, en vez de decidir por su cuenta.
- Con un solo estado hay que elegir entre perder mensajes o duplicarlos, **sin
  forma de saber cuál de las dos cosas pasó**. Un corte deja a lo sumo una
  intención en `unresolved`, visible, en vez de un número desconocido de
  duplicados silenciosos.
- `exactly-once` de punta a punta no existe y el diseño no lo promete. Lo que
  sí hace es volver la incertidumbre **acotada y visible**.
- La barrera autoritativa es el reclamo con `FOR UPDATE SKIP LOCKED`: dos
  workers no pueden llevarse la misma fila, y el segundo se lleva otras en vez
  de esperar.

---

## D-25 · El contenido se renderiza con reemplazo literal, no con un motor de plantillas

**Contexto.** El correo lleva datos del comprador. La plantilla la edita un
usuario del dashboard.

**Opciones consideradas.** Blade, que ya está en el proyecto y es lo más
cómodo; o Twig en modo sandbox, que es lo que recomienda el propio documento
de diseño para producción.

**Decisión.** Reemplazo literal de `{{ variable }}` contra una **lista blanca
declarada en la versión de la plantilla**. Sin condicionales, sin bucles, sin
acceso a objetos.

**Consecuencias.**

- Blade compila a PHP y ejecuta lo que le pongan. Una plantilla editable por
  un usuario, renderizada con datos de un comprador, es la superficie de
  ataque más atractiva del sistema. Un reemplazo literal no tiene ninguna.
- Una variable **no declarada hace fallar el renderizado**, no se reemplaza
  por vacío. Un correo que dice "Hola ," se descubre cuando lo recibe un
  cliente; un error de renderizado se descubre en el primer envío de prueba.
- El contexto es un objeto inmutable construido **por destinatario**. El
  riesgo real de un motor de plantillas no es la variable inexistente: es que
  el contexto de uno sobreviva al siguiente y una persona reciba un mensaje
  perfectamente formado con los datos de otra.
- El costo es expresividad: no se puede escribir "si compró más de X,
  mostrar esto". Para eso hay que separar en dos plantillas y dos condiciones,
  que además es más fácil de auditar.

---

## D-26 · La baja se guarda por dirección, no por cliente

**Contexto.** Un comprador se da de baja. El retailer reenvía su padrón al día
siguiente, posiblemente con otro identificador de origen.

**Decisión.** `suppressions` guarda el **hash del email**, no el
`customer_id`. Y la supresión se verifica **dos veces**: al congelar la
audiencia y otra vez justo antes de enviar.

**Consecuencias.**

- La baja vale para la persona, no para la fila. Atarla al cliente haría que
  un reenvío del padrón la borre sin que nadie lo note — el peor tipo de fallo
  de privacidad, porque el sistema sigue pareciendo correcto.
- El segundo chequeo existe porque entre resolver la audiencia y enviar pueden
  pasar horas. Respetar la baja sólo al congelar sería respetarla a medias.
  Hay un test que se da de baja **entre** los dos momentos.
- La ruta de baja es pública y firmada: pública porque exigirle una cuenta a
  un comprador para dejar de recibir correos es la forma más rápida de que
  marque el mensaje como spam; firmada porque sin firma cualquiera podría dar
  de baja a cualquiera conociendo el hash.
- El `GET` confirma y el `POST` ejecuta. No es formalismo: los clientes de
  correo y los escáneres de seguridad abren los enlaces automáticamente, y con
  la baja en el `GET` media lista se daría de baja sola.

---

## D-27 · La explicación distingue lo registrado de lo reconstruido

**Contexto.** El enunciado pide poder responder "¿por qué este cliente
recibió, o no recibió, este correo?", y aclara que es un requisito de
**producto**, no de infraestructura.

**Opciones consideradas.** Registrar la decisión para cada cliente evaluado.
Con 150.000 clientes y una audiencia de 200, eso son 149.800 filas de
exclusión por ejecución: más filas de "no" que de "sí", cada vez.

**Decisión.** Se **registra** lo que está acotado por el tamaño de la
audiencia —quién entró, quién quedó afuera por supresión, por email repetido o
por vencimiento— y se **reconstruye a demanda** lo que no: si un cliente
cumple o no la condición.

**Consecuencias.**

- La reconstrucción usa el **mismo árbol** de condiciones, compilado de otra
  forma (`ExplainCompiler`). Tener dos implementaciones de "qué significa esta
  condición" garantiza que en algún momento se contradigan, y la
  contradicción aparece justo cuando alguien pregunta por qué recibió algo que
  no debería. Es el mismo criterio que D-15 con los segmentos RFM.
- La respuesta viene **etiquetada**: `RECONSTRUIDO — evaluado ahora, no en el
  momento de la ejecución`. Los datos pudieron cambiar. Presentar una
  reconstrucción como si fuera un registro es peor que no responder.
- La ejecución guarda una **copia literal** de la condición, no una
  referencia. Si alguien edita la definición después, la explicación sigue
  siendo contra lo que realmente se resolvió.

---

## D-28 · Sin flujo de verificación de email

**Contexto.** El andamiaje de Breeze trae verificación de email: tres
controladores, tres rutas, una pantalla, y el middleware `verified` aplicado a
todo el dashboard.

**Decisión.** Se quitó entero.

**Consecuencias.**

- **El middleware no hacía nada.** `EnsureEmailIsVerified` comprueba
  `$user instanceof MustVerifyEmail` y deja pasar a quien no lo implemente.
  Nuestro `User` no lo implementa, así que la protección era aparente. Un
  middleware que parece proteger y no protege es peor que no tenerlo: alguien
  lo lee y asume una garantía que no existe.
- Es coherente con D-11: no hay auto-registro. Los usuarios los crea un
  administrador dentro de su organización, con el email ya verificado. No hay
  nadie a quien verificar.
- También se dejó de anular `email_verified_at` al cambiar el email desde el
  perfil. Sin flujo de verificación, anularlo dejaba al usuario en un estado
  que nada podía resolver.
- La columna se conserva: es convención de la tabla `users` de Laravel y el
  seed la usa. Lo que no se conserva es la maquinaria muerta alrededor.

---

## D-29 · La configuración del emisor se lee de `config/`, no de `env()`

**Contexto.** El emisor resuelve la URL de la API y su credencial por tenant.

**Decisión.** Las variables se declaran en `platform/config/d4s.php` y se leen
con `config()`. El contenedor `emitter` corre **con la configuración cacheada**,
a propósito.

**Consecuencias.**

- `env()` devuelve **null** cuando la configuración está cacheada. La versión
  anterior funcionaba sólo porque ese contenedor tenía el cacheo apagado — una
  coincidencia, no un diseño. Con `config:cache` activo, el emisor fallaba con
  "no hay credencial para [luna]", que manda a buscar un problema de permisos
  que no existe.
- Que el contenedor cachee la configuración ahora **es la prueba** de que el
  arreglo sirve: si alguien volviera a poner `env()` ahí, el emisor dejaría de
  funcionar en el acto en vez de esperar a producción.
- Es el mismo patrón que ya usaba el resto del proyecto para las conexiones de
  base (`config/database.php`).

---

## D-30 · El análisis estático es parte de la entrega, no un adorno

**Contexto.** Larastan se instaló al empezar el proyecto y no se corrió hasta
casi el final. Al correrlo: **119 hallazgos**.

**Decisión.** Se dejó en **0 errores en nivel 5**, y `phpstan.neon` queda en el
repositorio.

**Consecuencias.**

- Cuatro de los 119 eran problemas reales que **ningún test podía atrapar**,
  porque no eran fallos de comportamiento sino contratos mal declarados (ver
  D-28, D-29). Los otros 115 eran anotaciones faltantes.
- Esos 115 no eran bugs, pero eran el motivo por el que los cuatro estaban
  enterrados. Una herramienta con 119 hallazgos es una herramienta que nadie
  mira, y una herramienta que nadie mira es peor que no tenerla: aparece en
  `composer.json` sugiriendo un rigor que no se ejerció.
- Completar los `@property` y los genéricos de las relaciones tiene valor más
  allá del analizador: documenta cada modelo y hace que el editor sepa qué
  devuelve cada atributo.
- Dos correcciones fueron de código muerto que el analizador vio y la lectura
  no: una rama `if` imposible en el compilador del DSL, y un `map` que trataba
  filas de agregación como si fueran entidades.

---

## D-31 · La consola de API vive detrás del control de roles, no de una variable de entorno

**Contexto.** La consola (`/api-console`) se agregó tarde, como herramienta de
evaluación, protegida sólo por `app()->environment('local')`. El compose de
entrega fija `APP_ENV=local`, así que en la práctica estaba abierta y anónima.
La página muestra una API key, y una API key **salta por encima de la tabla de
roles del dashboard**: un analista que recibe 403 en `/clientes` podía copiar la
clave desde ahí y leer el padrón completo por `/api/v1/customers`.

**Decisión.** Tres candados, no uno: entorno `local`, sesión con capacidad de
administrador (la misma que gobierna `/usuarios`), y **sólo la credencial del
tenant de quien mira**.

**Consecuencias.**

- El enunciado pide en §3.2 que un rol no pueda hacer lo que no le toca «ni
  desde la interfaz ni por fuera de ella». Una comodidad de evaluación había
  abierto un camino alternativo a la garantía central de la Parte 2.
- El tercer candado es el que más importa y el que menos se ve: un
  administrador de `luna` viendo la clave de `andes` sería una fuga **entre
  tenants**, peor que la de rol. Antes la página listaba los tres.
- Para probar otro tenant hay que entrar como su administrador. Es más fricción,
  y es la fricción correcta.
- Fuera de `local` responde 404 y no 403: un 403 confirmaría que la ruta existe.
  Para un anónimo la respuesta es la redirección al login, igual que cualquier
  otra pantalla del dashboard.

## D-32 · El tablero de Horizon está cerrado para todos

**Contexto.** Horizon trae una puerta por defecto que devuelve
`app()->environment('local')`. Con `APP_ENV=local` en el compose, `/horizon`
respondía 200 a un anónimo: la cola completa, con payloads de trabajos de todos
los tenants, más los botones de reintentar y borrar.

**Decisión.** `Horizon::auth()` explícito que niega por defecto. Queda una
salida para depurar con **dos** condiciones simultáneas: la bandera
`D4S_HORIZON_DASHBOARD` encendida a mano y sesión de administrador.

**Consecuencias.**

- La cola es compartida, así que el tablero cruza tenants por construcción.
  Ningún usuario de esta plataforma pertenece a más de una organización, así
  que **ninguno tiene derecho a esa pantalla**. No es prudencia genérica: es la
  misma regla que aplica el resto del sistema.
- La vía normal de operación es la CLI dentro del contenedor
  (`php artisan horizon:status`), que no expone payloads de nadie.
- Un producto real resolvería esto con un plano de operador separado —otro
  dominio, otra identidad, fuera del alcance de esta prueba.

## D-33 · La recuperación de contraseña está acotada al tenant

**Contexto.** El flujo que trae Breeze resuelve todo por email a secas, y la
tabla `password_reset_tokens` tiene clave primaria `email`. Acá el email **no
identifica a nadie**: el único de `users` es `(tenant_id, email)` por decisión
explícita ([D-08](#d-08--el-email-no-identifica-a-un-cliente)), y el login pide
organización justamente por eso. Era la única puerta del sistema que no
distinguía tenants.

**Decisión.** El formulario pide organización, la tabla lleva `tenant_id` con
clave primaria `(tenant_id, email)` (migración nueva), el enlace del correo
lleva el slug, y un `TenantAwareTokenRepository` filtra por las dos columnas.

**Consecuencias.**

- Con el mismo email en dos organizaciones —el caso que justifica D-08— pasaban
  dos cosas: pedir el enlace en una borraba el token vigente de la otra, y un
  token emitido para una **validaba contra la otra**. Un reset legítimo podía
  cambiarle la contraseña a otra persona.
- Los cuatro tests de Breeze pasaban con el agujero adentro porque cada uno crea
  un solo usuario. El test nuevo crea dos y presenta el token de una
  organización con el slug de la otra.
- Reemplazar el repositorio obliga a sustituir el `PasswordBrokerManager`
  entero, y a sacar el proveedor de Laravel del mapa de servicios diferidos: se
  registra al resolver `auth.password` y pisa cualquier binding previo. El
  detalle está comentado en `AppServiceProvider` porque es exactamente el tipo
  de cosa que parece funcionar y no funciona.
- El pedido responde siempre lo mismo, exista o no la cuenta, igual que el
  login: distinguir «esa organización no existe» de «ese email no está» regala
  el padrón de organizaciones y de usuarios.

## D-34 · No se puede borrar la propia cuenta

**Contexto.** Breeze trae `DELETE /profile` porque asume un producto con
autoservicio, donde la cuenta es del usuario. Acá no lo es: las crea un
administrador dentro de su organización ([D-11](#d-11--los-usuarios-los-crea-un-administrador)).
La ruta dejaba pasar justo lo que `UserController::update` bloquea una pantalla
más allá: el último administrador podía sacarse a sí mismo del sistema.

**Decisión.** Se quita la ruta, el método y el formulario. La baja de un usuario
es desactivarlo desde `/usuarios`.

**Consecuencias.**

- Sin administradores no hay quien cree usuarios: la organización queda muerta
  hasta que alguien toque la base a mano.
- Desactivar en vez de borrar conserva el rastro de quién hizo qué.
- Es el mismo criterio que se aplicó al andamiaje de verificación de email
  ([D-28](#d-28--sin-flujo-de-verificación-de-email)): lo que Breeze trae para
  un producto que este no es, se saca, no se deja apagado.

## D-35 · El límite de tasa cuenta por credencial y se aplica antes de autenticar

**Contexto.** El único límite del sistema estaba en el login del dashboard. La
API pública no tenía techo: una credencial filtrada podía recorrer el padrón
entero a máxima velocidad, y un pipeline con un bucle mal escrito podía saturar
la ingesta de todos los tenants que comparten cluster.

**Decisión.** Dos cupos separados —300/min en ingesta, 120/min en consulta—,
contados por **credencial presentada** y aplicados **antes** del middleware de
autenticación.

**Consecuencias.**

- Antes de autenticar: así también queda limitado quien prueba claves al azar,
  que es tráfico que nunca llega a autenticarse. La clave nunca se guarda en
  claro en el almacén del limitador, sólo su SHA-256.
- Por credencial y no por IP: los clientes son pipelines de servidor, varias
  organizaciones pueden salir por la misma IP y una sola puede salir por
  varias. Contar por IP mezclaría cupos de organizaciones sin relación.
- Cupos separados porque las formas de uso son distintas: la ingesta manda pocos
  requests grandes y la consulta muchos chicos. Un único límite castigaría a uno
  de los dos.
- El 429 responde en JSON, con `Retry-After`. Devolverle la página HTML de error
  a un pipeline es el 429 más difícil de diagnosticar.
- Sigue sin haber cuota **por tenant**: dos credenciales del mismo retailer
  tienen el doble de cupo. Declarado en las limitaciones del README.
