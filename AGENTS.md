# AI Bridge — guía para agentes de IA

Eres un agente de IA (Codex, Claude u otro) conectándote a una tienda PrestaShop a través del módulo AI Bridge. Esta guía es autosuficiente — no necesitas nada más para trabajar.

## 0. Esto es una API HTTP, no un repositorio

No tienes acceso al filesystem de la tienda ni al código del módulo. **No busques carpetas, no ejecutes `find`/`ls`, no intentes clonar un repo.** Todo lo que necesitas son tres cosas que el humano te debe dar en el chat:

1. **URL base** de la tienda (ej. `https://tienda-del-cliente.com`).
2. **Token de API** (header `X-AI-Bridge-Token`).
3. Opcionalmente, **usuario/contraseña de Basic Auth** — solo si el sitio está detrás de una protección de servidor (staging). Lo sabrás porque una petición devolverá `401` con el header `WWW-Authenticate: Basic` en vez de un JSON del módulo.

Si te falta la URL o el token, **pídeselos directamente al humano y espera** — no los adivines, no los busques, no asumas un dominio.

**Sobre el token**: puede ser el token global de la tienda (compartido) o un token propio de un empleado (Back Office → Módulos → AI Bridge → Configurar → "Tokens por empleado"). Funcionalmente son iguales para todos los endpoints de catálogo; la diferencia es que un token de empleado te da tu propia memoria de conversación aislada (ver sección 2) y queda identificado en la auditoría (`created_by_employee_id`/`executed_by_employee_id`) en vez de aparecer como `0`.

Todas las llamadas tienen esta forma:
```
https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=<endpoint>
```
con el header `X-AI-Bridge-Token: <token>` siempre presente, y `-u usuario:contraseña` (Basic Auth) si el paso 3 aplica.

## 1. Cómo respondes (obligatorio)

- Nada de explicaciones largas. Muestra **solo el resultado**: qué cambió, con qué id, y listo. Después espera la siguiente instrucción.
- No expliques el flujo interno del módulo al humano salvo que pregunte.
- No hagas preguntas de aclaración en cadena — si falta un dato imprescindible (ver sección 5), pide exactamente ese dato en una línea y espera.
- Nunca inventes datos de producto (descripción, especificaciones, EAN) que no te hayan dado — pide la info real.

## 2. Primer paso siempre: verificar conexión

```bash
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=ping" \
  -H "X-AI-Bridge-Token: <token>"
```
Respuesta esperada: `{"success":true,"data":{"status":"ok","module":"aibridge"}}`.

Luego revisa el estado real antes de proponer nada:
```bash
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=diagnostics" \
  -H "X-AI-Bridge-Token: <token>"
```
Fíjate en `direct_apply_test_mode`: si es `false`, cada cambio que hagas queda en `status: "pending"` esperando aprobación humana en el Back Office — eso es normal, no un error, no lo reintentes.

`diagnostics` también te dice `authenticated_employee_id` — así confirmas con qué identidad quedó tu token (0 = token global/legacy, sin identidad propia).

### Memoria de conversación

Si tienes un token propio (no el global), puedes recuperar y guardar el historial de la conversación para retomarla en otra sesión — es un único historial por token, se sobrescribe completo cada vez que guardas (no es un log incremental):
```bash
# Recuperar (null si nunca se guardó nada)
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=conversation" \
  -H "X-AI-Bridge-Token: <token>"

# Guardar (reemplaza todo el historial anterior)
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=conversation" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"messages": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]}'
```
`messages` puede tener la forma que quieras (el módulo lo guarda como JSON tal cual, no lo interpreta) — mantén una convención consistente entre guardadas. Tope 512KB; si lo pasas, recorta el historial antes de guardar en vez de reintentar.

### Chat nativo en Back Office (widget flotante)

Cada empleado tiene una burbuja de chat visible en cualquier página del Back Office (Fase 2.5). Escribe ahí y su mensaje queda guardado con `{"role":"user","content":"...","at":"..."}` en su historial — el mismo que lees/escribes arriba con `GET/POST /conversation` usando el token de ESE empleado.

Si el humano te pide "revisá el chat" o "respondé lo que me escribieron en el Back Office":
1. `GET /conversation` con el token del empleado correspondiente.
2. Si el último mensaje tiene `"role":"user"` (nadie respondió todavía), esa es la solicitud pendiente.
3. Actúa (crea/edita lo que te pidan, usando el resto de esta guía) y después `POST /conversation` con el array completo de mensajes + tu respuesta agregada al final con `{"role":"assistant","content":"..."}`.
4. **La respuesta debe ser solo el resultado, no una explicación** (sección 1): si te pidieron editar un producto, contestá algo como "Precio de X actualizado a 12.50€." y ya — nada de narrar el proceso interno. El widget del empleado la muestra tal cual.

No hay todavía un proceso automático 24/7 escuchando el chat — respondés cuando el humano te pida que revises, o si estás corriendo con un loop de polling activo.

## 3. Identificar errores

| Lo que ves | Qué significa | Qué hacer |
|---|---|---|
| `401` con JSON `{"code":"unauthorized"}` | Token de API incorrecto o ausente | Pide el token correcto al humano |
| `401` con `WWW-Authenticate: Basic` (no es JSON del módulo) | El servidor tiene una protección de acceso previa (staging) | Pide usuario/contraseña de esa protección |
| `404` | El id que pediste (producto/categoría) no existe | Confirma el id, no lo inventes |
| `409` (`duplicate_*`) | Ya existe algo con esa referencia/nombre/link_rewrite | Cambia el valor o reutiliza el existente |
| `400` (`invalid_payload`) | Tu payload no pasó validación | Revisa `changes[].validation.errors` en la respuesta |
| `status: "pending"` en la respuesta | Flujo normal, falta aprobación humana | No es un error, espera o pide al humano que apruebe |
| `status: "failed"` | Se aprobó pero falló al ejecutar | Llama a `diagnostics` y lee `execution_error` de la última entrada |
| `500` | Error interno | Llama a `diagnostics`; si no aclara nada, repórtalo al humano tal cual, no reintentes en bucle |

## 4. El flujo no es opcional

```
preview (sin escribir) → solicitud "pending" → aprobación humana → ejecución → auditoría
```
Cada endpoint de creación/edición genera una solicitud con `approval_uuid`. Si `direct_apply_test_mode` está activo, se aprueba y ejecuta sola y la respuesta ya trae `status`/`applied`. Si no, queda `pending` — dilo al humano y sigue con otra tarea si tienes una, no te quedes reintentando.

## 5. Antes de crear cualquier cosa: consulta el catálogo existente

No dupliques categorías, marcas ni tags. Antes de crear, revisa:
- `GET /categories` — categorías existentes
- `GET /brands` — marcas existentes
- `GET /attributes` — grupos de atributos y sus valores (colores, tallas, etc. — necesario para combinaciones)

Si vas a crear/editar muchos productos, trabaja en lotes pequeños con `/products` paginado y `/batchpreview` (máx. 50 por llamada) — nunca asumas que ya conoces todo el catálogo de una sola vez, sin importar cuántos productos tenga la tienda.

Si te falta información de un producto (qué es, para qué sirve, especificaciones) para poder categorizarlo o redactar su SEO correctamente, pídesela al humano — no la inventes.

## 6. Endpoints de lectura

| Endpoint | Método | Qué hace |
|---|---|---|
| `ping` | GET | Verifica token y conectividad |
| `diagnostics` | GET (`?limit=N`) | Últimos errores y auditoría — úsalo cuando algo falle |
| `categories` | GET | Lista categorías activas |
| `brands` | GET | Lista marcas activas |
| `attributes` | GET | Grupos de atributos (color, talla...) con sus valores e ids — necesario antes de crear combinaciones |
| `products` | GET (`?page=&limit=` o `?reference=` o `?search=` o `?category_id=`) | Ver sección 6.1 — no uses solo paginación en catálogos grandes |
| `product` | GET (`?id=`) | Detalle completo de un producto |
| `productfields` | GET | Metadatos de qué campos existen y su tipo |

## 6.1 Verificar duplicados sin recorrer todo el catálogo

Si la tienda tiene miles de productos, **nunca** pagines todo `/products` para revisar si algo ya existe — es lento y no escala. Usa la vía rápida:

- **Por referencia/SKU (recomendado, O(1)):** `GET /products?reference=<SKU>` → `{"exists": true/false, "product": {...}}`. Además, cuando llames a `productcreatepreview` con una referencia que ya existe, la API la rechaza sola con `409 duplicate_product` — es decir, el propio endpoint de creación ya valida unicidad de referencia y de `link_rewrite` por ti. No necesitas pre-chequear si ya vas a intentar crear: solo maneja el `409` si ocurre.
- **Por nombre (búsqueda aproximada):** `GET /products?search=<texto>` — busca en nombre, referencia, EAN/ISBN/UPC (incluye productos inactivos). Los resultados de `search` traen datos parciales (sin precio real) — úsalos solo para detectar candidatos, y confirma con `GET /product?id=<id>` antes de decidir que es o no un duplicado.
- **Por categoría (para trabajar por bloques):** `GET /products?category_id=<id>&page=&limit=` — filtra el listado paginado a una sola categoría, útil para reorganizar la tienda por secciones en vez de recorrer todo el catálogo de una vez.

## 6.2 Editar miles de productos sin repetir ni perder el hilo

Este módulo está pensado sobre todo para esto: organizar catálogos grandes ya existentes (categorías, tags, SEO), no solo para crear productos nuevos.

1. **Divide el trabajo por categoría o por páginas fijas**, nunca "todo de una vez". Usa `category_id` para ir sección por sección, o `page`/`limit` (máx. 100) de forma secuencial y predecible (página 1, 2, 3... sin saltarte ni repetir).
2. **Lleva tu propia lista de progreso** (en tu memoria de sesión/notas, el módulo no la lleva por ti): id de producto, qué le hiciste, y si quedó pendiente algo. Antes de tocar un producto dentro de la misma sesión, revisa esa lista para no volver a procesarlo.
3. **Usa `batchpreview`/`batchapply`** (hasta 50 productos por llamada) en vez de una llamada por producto — mucho más eficiente en catálogos grandes.
4. **Para confirmar qué se editó realmente** (por si perdiste el hilo o quieres retomar en otra sesión), `GET /diagnostics?limit=N` te da las últimas ejecuciones con `product_id` y `changed_fields` — es el historial real del lado del servidor, úsalo para reconciliar tu propio progreso, no lo repitas de memoria si tienes dudas.
5. Si vas a re-etiquetar/re-categorizar todo el catálogo, hazlo por fases: primero un lote pequeño (10-20 productos), confirma con el humano que el criterio de categorización/tags es el correcto, y luego escala al resto — no proceses miles de productos con un criterio sin validar primero.

## 6.3 SEO y calidad profesional (obligatorio en toda creación/edición)

Trátalo como lo trataría un especialista en e-commerce/SEO, no como relleno de campos. Reglas concretas:

- **`meta_title`**: 50-60 caracteres. Nombre del producto + atributo distintivo (marca, modelo, característica clave). Único por producto — nunca copies el mismo título en dos productos.
- **`meta_description`**: 120-160 caracteres. Resume el beneficio real del producto e incluye una razón para hacer clic. Sin relleno de palabras clave repetidas ("funda funda iphone funda barata") — Google penaliza el keyword stuffing, no lo recompensa.
- **`link_rewrite`**: corto, descriptivo, en minúsculas, sin stopwords innecesarias, sin ids ni fechas. Coherente con `name`, no una copia larga del título.
- **`name`**: claro y específico (marca + producto + variante si aplica), no genérico ("Funda para iPhone" es peor que "Funda MagSafe iPhone 16 Pro Max Transparente").
- **`description`**: estructura profesional, no un párrafo plano — usa HTML con encabezados/lista de especificaciones/beneficios cuando el campo lo soporte (`description` acepta HTML limpio). Basa el contenido en la información real que te dieron; si falta algo (medidas, materiales, compatibilidad), pídelo en vez de inventarlo o dejarlo vacío sin avisar.
- **`description_short`**: 1-3 frases, el resumen que se ve en listados — no dupliques literalmente la descripción larga.
- **Tags y categorías**: usa términos que un comprador real buscaría, consistentes con cómo ya está categorizado el resto del catálogo (por eso revisa `/categories` y el catálogo existente antes de decidir). No inventes categorías nuevas si una ya existente sirve.
- **Imagen (`legend`)**: úsalo como texto alternativo real (qué muestra la imagen), no vacío ni genérico — también es SEO (Google Images).
- **Consistencia de catálogo**: mismas unidades, mismo formato de nombre, mismo tono en todos los productos que edites en una sesión — un catálogo con estilos mezclados se ve poco profesional y confunde al comprador.
- Nunca redactes afirmaciones que no puedas respaldar con la información dada (certificaciones, compatibilidad, garantías) — es un riesgo legal y de confianza para el negocio, no un detalle menor.

## 7. Crear un producto

```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=productcreatepreview" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{
    "shop_id": 1, "language_id": 3,
    "name": {"3": "Nombre del producto"},
    "link_rewrite": {"3": "slug-del-producto"},
    "price": 9.99, "id_tax_rules_group": 1, "reference": "SKU-UNICO",
    "categories": [2], "id_category_default": 2,
    "active": false,
    "tags": {"3": ["tag1", "tag2"]},
    "id_manufacturer": 1,
    "description": {"3": "<p>Descripción larga con HTML limpio</p>"},
    "description_short": {"3": "Resumen corto"},
    "meta_title": {"3": "Título SEO"},
    "meta_description": {"3": "Descripción SEO"},
    "meta_keywords": {"3": "palabra1, palabra2"},
    "images": {"add": [{"upload_token": "<token de /imagestage>", "cover": true, "legend": {"3": "texto alternativo"}}]},
    "features": [{"id_feature": 1, "custom_values": {"3": "Valor de prueba"}}],
    "stock": {"simple_quantity": 25},
    "combinations": {"create": [{"id_attributes": [8]}, {"id_attributes": [14]}]}
  }'
```
Notas:
- `name`/`link_rewrite` son mapas `{id_idioma: texto}`.
- `active` debe ser `false` al crear (arranca inactivo a propósito; actívalo después de revisarlo).
- `id_category_default` debe estar en `categories`.
- `tags` es opcional.
- `id_manufacturer` es opcional (default `0` = sin marca). Debe existir (`GET /brands` para ver ids válidos).
- `description`, `description_short`, `meta_title`, `meta_description`, `meta_keywords` son opcionales, mismo formato mapa `{id_idioma: texto}`. `description`/`description_short` deben ser HTML limpio (mismas reglas que en `productpreviewupdate`).
- `images` es opcional y solo acepta `{"add": [...]}` con **exactamente una imagen** (sube primero con `POST /imagestage`, mismo flujo que en `productpreviewupdate`). Para más imágenes, agrégalas después con `productpreviewupdate?id=<id>` (una por llamada, igual límite que ahí). No soporta `images.update` en la creación (no hay imágenes previas que editar).
- `features` es opcional, mismo formato que en `productpreviewupdate`: cada entrada usa `id_feature` + (`id_feature_value` si es un valor ya existente, o `custom_values` mapa `{id_idioma: texto}` si es libre). El feature debe existir; no hay endpoint para crear features nuevas.
- `stock` es opcional, solo `{"simple_quantity": N}` — si vas a crear combinaciones en la misma llamada, no envíes `stock` (el stock de combinaciones se maneja después con `productpreviewupdate`, igual que en un producto ya existente).
- `combinations` es opcional, mismo formato que en `productpreviewupdate`: solo `{"create": [...]}`, hasta 30 en un lote (`GET /attributes` primero para conocer los `id_attribute` válidos). La primera combinación del lote queda automáticamente como default (el producto recién creado no tiene ninguna todavía). No acepta `combinations.update` en la creación (no hay combinaciones previas que editar).
- Ya no hay campos que falten en `product.create` — soporta todo lo mismo que `productpreviewupdate`, salvo que en la creación no se puede editar algo que aún no existe (por eso `images`/`combinations` solo aceptan `add`/`create`, nunca `update`).
- **No soporta descuentos/precio rebajado** — eso no existe todavía en el módulo. Dilo directamente si te lo piden, no lo intentes simular con el campo `price`.

## 8. Editar un producto existente

```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=productpreviewupdate&id=<id_producto>" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"active": true, "id_manufacturer": 6, "price": 12.5}'
```
Envía solo los campos que cambian. Soportados: `price`, `active`, `reference`, `minimal_quantity`, `ean13`, `isbn`, `upc`, `wholesale_price`, `id_tax_rules_group`, `available_for_order`, `show_price`, `condition`, `out_of_stock`, `weight`, `width`, `height`, `depth`, `name`, `description`, `description_short`, `meta_title`, `meta_description`, `meta_keywords`, `link_rewrite`, `id_manufacturer`, `categories`, `id_category_default`, `features`, `tags`, `stock`, `combinations`, `images`, `combination_images`.

**Tags:** mapa `{id_idioma: ["tag1","tag2"]}`. Solo se tocan los idiomas incluidos como clave; para vaciar un idioma envía `[]`.

## 9. Crear/editar categorías

Crear (campos de texto van como mapa `{id_idioma: texto}`, igual que en producto, y `active` debe ser `false`):
```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=categorycreatepreview" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"shop_id":1,"language_id":3,"id_parent":2,"name":{"3":"Nombre"},"link_rewrite":{"3":"slug"},"active":false}'
```
Opcionales en la creación: `description`, `meta_title`, `meta_description`, `meta_keywords` (mismo formato mapa).

Editar (aquí los campos de texto van como **string simple**, no mapa — operan en el idioma del contexto de la API):
```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=categorypreviewupdate&id=<id_categoria>" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"active": true, "meta_title": "Título SEO", "id_parent": 5}'
```
Campos: `id_parent`, `name`, `link_rewrite`, `description`, `meta_title`, `meta_description`, `meta_keywords`, `active`. Mover una categoría dentro de sí misma o de su propia subcategoría se rechaza automáticamente.

## 10. Crear marca/fabricante

```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=manufacturercreatepreview" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"shop_id":1,"language_id":3,"name":"Nombre de la marca","active":false}'
```
Opcionales: `description`, `short_description`, `meta_title`, `meta_description`, `meta_keywords` (mapa `{id_idioma: texto}`). `active` debe ser `false` al crear. No hay endpoint para editar una marca ya creada todavía — usa el Back Office para eso.

## 11. Combinaciones (variantes: color, talla...)

### 11.1 Paso 1 — obtener los ids reales de los atributos (obligatorio, siempre)

```bash
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=attributes" \
  -H "X-AI-Bridge-Token: <token>"
```
Respuesta: grupos (`id_attribute_group`, `name` — ej. "Color") con sus valores (`id_attribute`, `name` — ej. `{"id_attribute": 14, "name": "Azul"}`). **Usa esos `id_attribute` exactos.** Nunca inventes ids, nunca uses el nombre del color como si fuera un id, y nunca pidas "el SKU de la variante" al humano para poder crear la combinación — **la referencia/SKU es opcional**, no es un requisito para crear.

Si el color/talla que necesitas no aparece en la lista, dilo directamente ("el atributo X no existe, ¿lo creo yo o lo creas tú desde el Back Office?") — este módulo no tiene endpoint para crear atributos nuevos todavía.

### 11.2 Paso 2 — crear las combinaciones (hasta 30 por llamada)

`combinations.create` acepta una **lista** de combinaciones en una sola llamada — no hace falta una petición por color:
```json
{"combinations": {"create": [
  {"id_attributes": [8]},
  {"id_attributes": [14]},
  {"id_attributes": [11]}
]}}
```
(`id_attributes` es un array porque una combinación puede cruzar varios grupos, ej. `[14, 20]` = Azul + Talla M — un id por grupo distinto, nunca dos ids del mismo grupo). Opcionales por entrada: `reference`, `ean13`, `isbn`, `upc`, `price_impact`, `wholesale_price`, `weight_impact`, `minimal_quantity`, `available_date` — omítelos si no los tienes, no bloquees la creación por esto.

La primera combinación que crees en un producto sin combinaciones previas queda automáticamente como la variante por defecto (`default_on`); las siguientes no. Esto es automático, no necesitas indicarlo.

**Límite real: máx. 30 combinaciones nuevas por llamada.** Si necesitas más (ej. 5 colores × 10 tallas = 50), divide en varias llamadas de `productpreviewupdate` seguidas — cada llamada ve el estado ya creado por la anterior.

### 11.3 Editar combinaciones ya existentes (no para crear)

`combinations.update` es solo para modificar combinaciones que **ya existen** — se identifican por `id_product_attribute` (lo obtienes de `GET /product?id=X`, campo `combinations[].id_product_attribute`), no por color ni por id de atributo:
```json
{"combinations": {"update": [{"id_product_attribute": 123, "price_impact": 2.5}]}}
```
Si intentas usar `update` para crear algo nuevo, el módulo lo rechaza con `Invalid combination update payload` — esa es la señal de que estás usando el verbo equivocado, cambia a `create`.

### 11.4 Stock

Por combinación:
```json
{"stock": {"combinations": [{"id_product_attribute": 123, "quantity": 40}]}}
```
Simple (producto sin combinaciones):
```json
{"stock": {"simple_quantity": 40}}
```

## 12. Subir e insertar una imagen

Paso 1 — subir el archivo (multipart, no JSON):
```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=imagestage" \
  -H "X-AI-Bridge-Token: <token>" \
  -F "file=@/ruta/local/imagen.jpg;type=image/jpeg"
```
Responde con un `upload_token` válido 24h. Paso 2 — asociarla al producto:
```json
{"images": {"add": [{"upload_token": "<upload_token>", "cover": true, "legend": {"3": "Descripción de la imagen"}}]}}
```
en un `productpreviewupdate`. Si tu herramienta HTTP no soporta multipart/form-data, dilo explícitamente al humano en vez de intentar mandar la imagen como JSON o base64 — no funcionará.

## 13. Lotes (hasta 50 items)

```bash
curl -s -X POST ".../batchpreview" -H "X-AI-Bridge-Token: <token>" -d '{"items":[{"product_id":1,"changes":{"active":true}},{"product_id":2,"changes":{"active":true}}]}'
curl -s -X POST ".../batchapply" -H "X-AI-Bridge-Token: <token>" -d '{"approval_uuids":["uuid1","uuid2"]}'
```

## 13.1 Descuentos

Campo `discount` en `productpreviewupdate`. Aplicar/editar:
```json
{"discount": {"reduction_type": "percentage", "reduction": 0.15}}
```
`reduction_type`: `"percentage"` (fracción 0-1, ej. `0.15` = 15%) o `"amount"` (importe fijo en la moneda de la tienda). Opcionales: `from_quantity` (default 1), `from`/`to` (fechas `"YYYY-MM-DD HH:MM:SS"`, sin límite si se omiten). Quitar el descuento:
```json
{"discount": {"active": false}}
```
Es un único descuento "para todos" por producto (no por grupo de cliente, país, moneda ni cantidad escalonada) — para reglas más finas, usa el Back Office. `GET /product?id=X` devuelve el descuento activo en `discount`, incluyendo `price_tax_excl_after_discount` ya calculado.

## 15. Pedidos, clientes y direcciones

### 15.1 Pedidos (solo lectura)

```bash
# Listado (filtros opcionales: status_id, customer_id, page, limit)
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=orders&limit=20" \
  -H "X-AI-Bridge-Token: <token>"

# Detalle completo (cliente, direcciones, productos, historial de estados)
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=orders&id=<id_pedido>" \
  -H "X-AI-Bridge-Token: <token>"
```
No hay endpoint para cambiar el estado de un pedido ni para crear pedidos todavía — solo consulta.

### 15.2 Clientes

```bash
# Buscar por nombre, email (o teléfono si no hay match por nombre/email)
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=customers&search=<texto>" \
  -H "X-AI-Bridge-Token: <token>"

# Detalle con sus direcciones y cantidad de pedidos
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=customers&id=<id_cliente>" \
  -H "X-AI-Bridge-Token: <token>"
```

### 15.3 Crear una dirección

```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=addresscreatepreview" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{
    "id_customer": 3, "alias": "Envío", "firstname": "Nombre", "lastname": "Apellido",
    "address1": "Calle 123", "city": "Madrid", "postcode": "28001", "id_country": 6,
    "phone": "600000000"
  }'
```
Campos obligatorios: `id_customer` (debe existir), `alias`, `firstname`, `lastname`, `address1`, `city`, `id_country`, `postcode`. Al menos uno de `phone`/`phone_mobile` es obligatorio. Opcionales: `address2`, `id_state`, `company`, `other`, `dni`, `vat_number`.

**Ojo con países que exigen identificación fiscal** (España entre ellos): si el país lo requiere, `dni` pasa a ser obligatorio también — el error te lo va a decir explícito (`"Missing dni (required for this country)."` en el preview, o el mensaje real de PrestaShop si ya pasó validación), no lo trates como un error genérico, solo pide el DNI/NIF al humano.

**`id_state`**: solo es obligatorio si el país tiene provincias/estados (`contains_states`); para la mayoría de países se ignora.

### 15.4 Editar una dirección existente

```bash
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=addresspreviewupdate&id=<id_direccion>" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"phone": "600111222", "city": "Barcelona"}'
```
Envía solo los campos que cambian. Mismos campos que en la creación, salvo `id_customer` (no se puede reasignar una dirección a otro cliente por API — usa el Back Office para eso).

## 15.5 Correos con plantillas

```bash
# Listar plantillas
curl -s "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=emailtemplates" \
  -H "X-AI-Bridge-Token: <token>"

# Crear una plantilla nueva (name: minúsculas/números/guiones, 3-64 chars)
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=emailtemplatecreatepreview" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"name": "aviso-stock", "subject": "Hola {{firstname}}", "html_body": "<p>Hola {{firstname}}, tu pedido de {{product_name}} está listo.</p>"}'

# Enviar usando una plantilla ya creada
curl -s -X POST "https://<URL_BASE>/index.php?fc=module&module=aibridge&controller=emailsendpreview" \
  -H "X-AI-Bridge-Token: <token>" -H "Content-Type: application/json" \
  -d '{"template": "aviso-stock", "to": "cliente@example.com", "to_name": "Nombre", "variables": {"firstname": "Nombre", "product_name": "Funda X"}}'
```
`{{clave}}` en `subject`/`html_body` se sustituye por el valor de `variables` al enviar — si te falta una variable, el placeholder queda visible tal cual en el correo (no se envía en blanco), así que revisa el preview (`preview_subject`/`preview_html`) antes de confirmar.

**Si el envío falla con un error de infraestructura** (ej. `proc_open`, timeout de conexión SMTP): no es algo que puedas arreglar por API — significa que la tienda no tiene un SMTP real configurado en Back Office → Parámetros de la tienda → Correo. Dilo directo al humano, no lo reintentes ni lo simules.

## 14. Lo que este módulo NO hace todavía

- Descuentos por grupo de cliente/país/moneda o por escalón de cantidad (solo el descuento simple de la sección 13.1).
- Editar una marca ya creada (solo crear).
- Eliminar productos, categorías, marcas o direcciones.
- Crear features (características) nuevas — solo asignar valores a features que ya existen en la tienda.
- Crear grupos de atributos o valores de atributo nuevos (colores, tallas) — solo usar los que ya existen (`GET /attributes`).
- Cambiar el estado de un pedido, crear pedidos, o reasignar una dirección a otro cliente.
- Editar o borrar una plantilla de correo ya creada (solo crear y listar).
- Enviar un correo real si la tienda no tiene SMTP configurado en el Back Office — el módulo hace todo lo suyo bien (plantilla, preview, aprobación) pero la entrega depende de esa configuración, que no se puede hacer por API.

Si te piden alguna de estas, dilo directo, no lo simules con otro campo.

## 16. Sos otro Codex/agente y te acaban de conectar a este módulo

Si el humano te pegó este archivo (o te dio la URL) porque quiere que respondas por el **chat nativo del Back Office** (la burbuja "AI" que aparece en cualquier página del admin), hacé esto:

1. Con el token que te dieron (de un empleado específico, no el global), `GET /conversation` (sección "Chat nativo en Back Office" más abajo).
2. Si el último mensaje del array tiene `"role":"user"`, es un pedido sin responder — actuá usando el resto de esta guía (catálogo, pedidos, clientes, direcciones).
3. `POST /conversation` con el array completo + tu respuesta agregada como `{"role":"assistant","content":"..."}`.
4. **La respuesta va SOLO con el resultado** ("Precio actualizado a 12.50€.", "Dirección creada, id 23.") — nada de explicar el proceso, nada de preámbulos. El humano lo va a leer en un cuadro de chat chico, no en una consola.
5. Ejecutá directo (sin dejar nada pendiente de aprobación) salvo que `direct_apply_test_mode` esté apagado — en ese caso decilo en la respuesta ("Cambio propuesto, queda pendiente de aprobación en el Back Office.") en vez de fingir que ya se aplicó.
6. No hay nadie escuchando el chat 24/7 — si el humano quiere que sigas respondiendo, va a pedirte que "revises el chat" de nuevo cada tanto.
