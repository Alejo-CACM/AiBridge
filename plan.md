# AI Bridge — Plan de tareas para agente

Lee este archivo completo antes de tocar cualquier código. No asumas nada que no esté escrito aquí o en el propio repositorio.

## 1. Qué es este proyecto

AI Bridge es un módulo para PrestaShop 8.2.0 que permite que una IA gestione el catálogo (productos, categorías, marcas, SEO) de forma controlada, nunca directa. El flujo de seguridad es innegociable:

```
API con token → preview (sin escribir en BD) → solicitud "pending" → aprobación humana → executor → auditoría
```

Ninguna tarea de este plan debe saltarse ese flujo en el entorno de producción. En el entorno de pruebas existe un modo auto-approve (`AIBRIDGE_DIRECT_APPLY_TEST_MODE`, ver sección 4), pero **nunca debe activarse en producción** (es un checkbox manual sin protección técnica — ver Notas).

Guía para agentes que se conectan por la API: [AGENTS.md](AGENTS.md).

## 2. Reglas para el agente

1. Trabaja únicamente sobre el sitio/base de datos de pruebas, nunca sobre datos reales de catálogo.
2. Antes de intentar cualquier operación de escritura, revisa `GET /aibridge/diagnostics` para ver el estado y los últimos errores.
3. Después de cada intento (éxito o fallo), vuelve a revisar diagnósticos y anota en este archivo qué pasó, no solo si "compiló".
4. Marca una tarea como `[x]` únicamente si la probaste end-to-end contra la API real del módulo, no solo revisando el código.
5. Si una tarea te bloquea más de lo esperado, no la saltes en silencio: escribe en la sección "Notas y bloqueos" qué intentaste y por qué falló, y pasa a la siguiente tarea independiente si existe.
6. No modifiques el flujo de aprobación humana fuera del entorno de pruebas.

## 3. Estado actual (completado y validado end-to-end)

* [x] A1–A3: módulo base, token API seguro, endpoint `/ping`
* [x] A4: lectura de categorías, marcas, campos, productos y producto individual
* [x] A5: preview/diff sin escritura, validaciones, aprobación humana desde Back Office
* [x] A7: batch preview y batch apply, siempre con aprobación humana
* [x] A8–A9: executor controlado y auditoría de ejecuciones success/failed
* [x] A10–A11: campos simples y textos/SEO multidioma
* [x] A12: fabricante y categorías
* [x] A13: características de producto
* [x] A14: stock y edición de combinaciones existentes
* [x] A15: leyendas, portada, posiciones, subida temporal e imágenes; asociaciones a combinaciones
* [x] A17.1: estructura para `product.create` y endpoint de preview de creación
* [x] Fase 0.2: endpoint `GET /aibridge/diagnostics` (ver sección 4)
* [x] Fase 1: causa raíz del bloqueo de `product.create` encontrada y corregida (ver sección 5)
* [x] Fase 2: creación real de producto simple validada end-to-end contra `saruia.es` (producto id 31154)

## 4. Fase 0 — Entorno de pruebas

* [ ] 0.1 Activar `_PS_MODE_DEV_ = true` en el sitio de pruebas — **no hecho, decisión pendiente**. El endpoint de diagnósticos (0.2) ya cubre la necesidad original ("que la IA vea errores sin shell") sin necesitar debug mode global, que expondría trazas PHP a cualquier visitante. Recomendación: dejarlo apagado salvo que se necesite el profiler de Symfony.
* [x] 0.2 `GET /aibridge/diagnostics` — implementado en `controllers/front/diagnostics.php`. Protegido con el mismo token (`X-AI-Bridge-Token`). Devuelve: versión del módulo, `debug_mode`, `direct_apply_test_mode`, último error SQL de la request actual, últimas N entradas de `aibridge_log` y de `aibridge_approval_request` (con `execution_error`). Parámetro `?limit=N` (máx. 100, default 20).
* [x] 0.3 `AIBRIDGE_DIRECT_APPLY_TEST_MODE` ya existe (checkbox en Back Office → Módulos → AI Bridge → Configurar). **Decisión explícita del usuario (2026-08-06): NO atarlo a `_PS_MODE_DEV_`.** Se mantiene como checkbox manual independiente, sin protección técnica contra activarse en producción. Riesgo aceptado conscientemente — no se debe "arreglar" esto sin nueva instrucción explícita.
* [x] 0.4 Script de smoke test: `test/smoke_test.sh`. Uso: `AIBRIDGE_URL="https://tienda.com" AIBRIDGE_TOKEN="..." AIBRIDGE_BASIC_AUTH="user:pass" ./test/smoke_test.sh` (el tercer var es opcional, solo si hay protección de staging).

## 5. Fase 1 — Causa raíz de `product.create` (RESUELTO 2026-08-06)

Dos bugs de compatibilidad con PrestaShop 8.2, ambos en `classes/AiBridgeProductCreateExecutor.php`, encontrados gracias al detalle de excepción capturado en el log de auditoría (visible solo vía `/aibridge/diagnostics`, nunca en la respuesta pública de la API):

1. **`buildProduct()` (línea ~119/125):** usaba `ProductType::TYPE_STANDARD` y `RedirectType::TYPE_DEFAULT` sin namespace. En PrestaShop 8.x estas clases NO están en el namespace global, viven en `PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\`. Causaba `Error: Class "ProductType" not found` antes de siquiera intentar el insert — nunca llegaba a tocar la base de datos, por eso `Db::getMsgError()` siempre estaba vacío. **Fix:** usar el FQCN completo (`\PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_STANDARD`).
2. **`captureAddContext()` (línea ~176):** leía `$product->id_shop` e `$product->id_lang`, ambas `protected` en `ObjectModel` en esta versión de PrestaShop (no hay getter público). Causaba `Error: Cannot access protected property Product::$id_shop`. **Fix:** usar `id_shop_default` (público) y pasar el `language_id` ya conocido como parámetro en vez de leer la propiedad protegida.

Lección para el resto del módulo: **antes de asumir que una clase o propiedad de PrestaShop está disponible como en versiones anteriores, verificar en el core real del sitio** (namespace, visibilidad). El resto de handlers (`AiBridgeApprovalExecutor` y compañía) no fueron afectados porque no tocan estas clases/propiedades.

## 6. Fase 2 — Validación de creación real (COMPLETADO 2026-08-06)

* [x] 2.1 Producto creado vía flujo completo (API real, test mode auto-approve activo en `saruia.es`): inactivo, sin stock, sin imágenes, sin combinaciones.
* [x] 2.2 Confirmado leyendo de vuelta con `GET /aibridge/product?id=31154`: `active:0`, `quantity:0`, `combinations:[]`, `images:[]`, categorías y precio correctos.
* [x] 2.3 Auditoría confirma `created_product_id: 31154` en `aibridge_approval_request` y en `aibridge_log` (`result: success`).

Nota: quedó un producto de prueba (`id 31154`, referencia `AIBRIDGE-TEST-004`, nombre `AIBRIDGE TEST PRODUCT 4 - borrar`) en `saruia.es`. Está inactivo y no aparece en la tienda pública, pero alguien debería borrarlo manualmente desde el Back Office cuando ya no se necesite para pruebas (el módulo no tiene endpoint de borrado — ver sección 8).

## 7. Fase 3 — Rollback ante fallos parciales

El código YA implementa rollback granular (no es una tarea pendiente de diseño, ya existe):

* `AiBridgeProductCreateExecutor::rollback()` — borra el producto creado si algo falla después del insert (categorías, verificación, etc.) y confirma que quedó completamente eliminado (sin atributos, sin imágenes).
* `AiBridgeApprovalExecutor` (flujo de actualización) — tiene rollback específico por sub-sistema: imágenes (`AiBridgeImageHandler::restore`), combinaciones (`AiBridgeCombinationHandler::restore` / `AiBridgeCombinationCreateHandler::rollback`), stock, features, clasificación (categorías/manufacturer). Si el rollback mismo falla, el error se marca explícitamente como "... requires manual review." en vez de fingir éxito.

* [ ] 3.1–3.3 Pendiente: **provocar deliberadamente** un fallo a mitad de creación/actualización contra `saruia.es` para confirmar en vivo que el rollback deja la BD limpia (por ahora solo se validó por lectura de código). Estrategia sugerida: forzar un `id_category_default` que no pertenezca a `categories` después de que el producto ya se creó pero antes de `addToCategories`, o similar.

## 8. Fase 4 — Ampliar `product.create` gradualmente

Sin cambios respecto al plan original — pendiente, siguiendo el patrón preview → pending → aprobación → ejecución → auditoría con detalle de error si falla:

* [x] 4.1 Fabricante en creación (`id_manufacturer`, opcional, default 0). `AiBridgeProductCreatePreview` valida con `AiBridgeClassificationHandler::validateField`, `AiBridgeProductCreateExecutor::buildProduct`/`verify` lo aplican y comprueban. Validado end-to-end en `saruia.es`: producto 31166 creado con `id_manufacturer:1`, y producto sin el campo confirmado con `id_manufacturer:0`.
* [x] 4.2 Textos adicionales / SEO multidioma en creación (`description`, `description_short`, `meta_title`, `meta_description`, `meta_keywords`, mapa `{id_idioma: texto}`, todos opcionales). Validado end-to-end en `saruia.es`: producto 31168 creado con los 5 campos; confirmado por lectura (`GET /product`, salvo `meta_keywords` que ese endpoint no expone) y por `productpreviewupdate` con el mismo valor devolviendo `changes: []`.
* [x] 4.3 Imágenes en creación (campo `images` opcional, solo `{"add":[{...}]}` — una imagen por creación, igual límite que `productpreviewupdate`; para más imágenes usar `productpreviewupdate` después). Reutiliza `AiBridgeImageHandler` completo (normalize/capture/apply/verify/consumeAddedUpload). Bug real encontrado y corregido durante la prueba: el executor de creación vuelve a correr `AiBridgeProductCreatePreview::build()` sobre el payload ya canonicalizado (no sobre el crudo) para re-validar antes de ejecutar — para la mayoría de campos es idempotente, pero la forma canónica de `images` (`id_aibridge_upload`, no `upload_token`) no era aceptada por `normalize()`; se agregó fallback a `normalizeCanonical()` en `build()`. Segundo bug encontrado: al integrar el handler en el executor de creación olvidé llamar `capture()` antes de `apply()`, por lo que el snapshot no tenía `upload_id` y `consumeAddedUpload()` no marcaba el token como consumido (permitía reusarlo indefinidamente) — corregido llamando `capture()` primero. Validado en vivo en `saruia.es`: producto 31171 creado con imagen (cover, legend), y confirmado que un token ya usado es rechazado (`400 invalid_payload`) en un segundo intento.
* [x] 4.4 Características en creación (campo `features` opcional, mismo formato/`AiBridgeFeatureHandler` que `productpreviewupdate`: valor existente vía `id_feature_value` o `custom_values` por idioma). Sin cambios de código necesarios en el handler, solo cableado en preview/executor de creación. Validado end-to-end: producto 31172 creado con `{"id_feature":1,"custom_values":{"3":"Prueba AI Bridge"}}`, confirmado por lectura.
* [x] 4.5 Stock inicial en creación (campo `stock` opcional, solo `{"simple_quantity": N}` — el producto no puede tener combinaciones todavía en este paso, así que no aplica la variante `combinations` de `AiBridgeStockHandler`). Validado end-to-end: producto 31173 creado con `stock:{"simple_quantity":25}`, confirmado con `quantity:25` en `GET /product`.
* [x] 4.6 Combinaciones en creación (campo `combinations` opcional, solo `{"create":[...]}`, mismo `AiBridgeCombinationCreateHandler` que ya soporta lotes de hasta 30 en `productpreviewupdate`; la primera del lote se marca `default_on` automáticamente porque el producto recién creado no tiene combinaciones previas). `AiBridgeProductCreateExecutor::verify()` ajustado: el chequeo de `hasAttributes()` ya no exige siempre "sin combinaciones", ahora compara contra si se pidieron combinaciones o no. Validado end-to-end: producto 31174 creado con 2 combinaciones (Blanco default, Azul no-default), confirmado por lectura.

Con 4.6 queda **completa la Fase 4** — `product.create` ahora soporta fabricante, textos/SEO multidioma, una imagen, características, stock inicial y combinaciones, todo en el mismo payload de creación.

## 9. Fase 5 — Preparar el módulo para producción

* [ ] 5.1 `AIBRIDGE_DIRECT_APPLY_TEST_MODE` sigue sin restricción técnica (decisión consciente, ver sección 4/0.3). Antes de ir a producción real, confirmar con el dueño del proyecto que el checkbox está desactivado.
* [ ] 5.2 Revisar que `/aibridge/diagnostics` no exponga de más. Hoy devuelve `execution_error` (que puede incluir detalle de excepción con nombre de clase/archivo/línea desde el fix de la Fase 1) y payloads de auditoría — apropiado para debug protegido por token, pero **no debe quedar público sin token** ni cachearse en un CDN.
* [ ] 5.3 Ejecutar el smoke test completo contra producción en modo solo-lectura antes de habilitar escritura real.

## 10. Fase 6 — Gestión completa de catálogo (pedido por el usuario, 2026-08-06, aún NO implementado)

Visión del usuario: empresas con catálogos grandes y mal organizados (categorías/etiquetas/SEO inconsistentes) necesitan que un agente de IA (conectado vía este módulo) pueda reorganizar el catálogo completo de forma segura — crear y editar productos, crear categorías, etiquetar productos, mejorar SEO/URLs — sin tumbar la tienda, y con memoria de qué se creó/editó y por qué (esto último ya lo cubre `aibridge_log` + `/diagnostics`).

Piezas concretas identificadas, en orden sugerido de implementación (cada una repite el mismo patrón preview → pending → aprobación → ejecución → auditoría; no saltarse el patrón aunque parezca una operación "simple"):

* [x] 6.1 **Tags de producto** — implementado y validado (`AiBridgeTagHandler.php`), soportado tanto en `productpreviewupdate` como en `productcreatepreview` (6.1-b, campo opcional `tags` en el mismo payload de creación).
* [x] 6.2 **Crear categorías** (`category.create`) — `AiBridgeCategoryCreatePreview.php` + `AiBridgeCategoryCreateExecutor.php` + controller `categorycreatepreview.php`. Validado end-to-end en `saruia.es` (categoría id 50). Bug encontrado y corregido durante la prueba: `verify()` cargaba la `Category` con `id_lang` específico (modo string) pero leía `->name[$languageId]` como si fuera array — corregido cargando con `id_lang = null` (modo array multi-idioma), igual que ya se hacía en el executor de productos.
* [x] 6.3 **Editar categorías existentes** (`category.update`) — `AiBridgeCategoryPreview.php` + `AiBridgeCategoryExecutor.php` + controller `categorypreviewupdate.php`. Campos: `id_parent` (con guardia anti-circular vía `Category::checkBeforeMove`), `name`, `link_rewrite`, `description`, `meta_title`, `meta_description`, `meta_keywords`, `active`. Validado end-to-end (categoría id 50: activada + `meta_title` actualizado).
* [x] 6.4 **`GET /aibridge/attributes`** — lista grupos de atributos (color, talla...) con sus `id_attribute`, necesario para que un agente pueda crear combinaciones sin adivinar ids. Encontrado como bloqueo real en una prueba con otro agente (ver notas 2026-08-07).
* [x] 6.5 **Crear marca/fabricante** (`manufacturer.create`) — `AiBridgeManufacturerCreatePreview.php` + `AiBridgeManufacturerCreateExecutor.php` + controller `manufacturercreatepreview.php`. Solo creación, no edición todavía. Validado end-to-end (marcas id 5 y 6 creadas).
* [ ] 6.2 **Crear categorías nuevas** (`category.create`): mismo patrón que `product.create` (preview propio, `AiBridgeApprovalRequest::OPERATION_CREATE` ya soporta múltiples tipos de operación si se generaliza `operation_type`, o se añade un tipo nuevo `create_category`). Cuidado: replicar la lección de la Fase 1 — verificar en el core real de `saruia.es` qué propiedades/clases son públicas antes de asumir compatibilidad.
* [ ] 6.3 **Editar categorías existentes** (nombre, categoría padre, descripción, SEO de categoría).
* [ ] 6.4 Endpoint de solo-lectura para que el agente pueda pedir más contexto de un producto antes de decidir cómo categorizarlo/etiquetarlo (ya existe parcialmente vía `GET /aibridge/product`; evaluar si falta algo, p. ej. tags actuales, historial de ediciones previas del mismo producto vía `/diagnostics`).

No implementar 6.2/6.3 sin volver a correr el mismo proceso de esta sesión (probar en vivo contra `saruia.es`, no asumir que compila y ya funciona) — la Fase 1 mostró que asumir compatibilidad de la API de PrestaShop sin probar en el core real cuesta caro.

## 11. Ideas para el futuro (explícitamente fuera de alcance por ahora)

El usuario quiere, más adelante, poder distribuir este módulo a otros clientes con un mecanismo de actualización remota (subir una nueva versión a algún lugar y que las instalaciones detecten la actualización) y que el módulo no sea gratuito (licenciamiento). **No implementar todavía.** Al tocar código nuevo, evitar cerrar puertas a esto sin necesidad (p. ej., no hardcodear asunciones de "instalación única"), pero tampoco construir infraestructura de licenciamiento/actualización especulativa ahora — no hay requisitos concretos todavía.

## 11.1 Gaps confirmados por prueba real con otro agente (2026-08-07)

Se corrió una sesión real con otro agente de IA contra `saruia.es` (transcript aportado por el usuario). Encontró estos huecos reales, ya resueltos salvo el de descuentos:

* Sin endpoint para listar atributos → no podía crear combinaciones de color sin adivinar ids. **Resuelto** (6.4).
* Sin endpoint para crear marcas → no podía asignar "Easoncase" si no existía. **Resuelto** (6.5).
* Sin soporte de descuentos/precio rebajado en la creación de producto. **NO resuelto** — requiere implementar `SpecificPrice` (tipo de reducción, fechas, scoping por tienda/moneda/grupo). Documentado como "no soportado" en `AGENTS.md` sección 14 para que el agente lo diga directo en vez de dar vueltas pidiendo aclaraciones. Candidato para próxima sesión si se necesita.
* El agente probado intentó buscar el módulo/AGENTS.md en el filesystem local antes de conectarse por HTTP — `AGENTS.md` ahora abre con una sección 0 explícita: "esto es una API HTTP, no un repositorio", más instrucciones de qué pedir si falta URL/token/Basic Auth.
* El agente probado daba respuestas largas explicando cada paso — `AGENTS.md` ahora tiene sección 1 "cómo respondes": solo resultado, sin explicaciones, esperar siguiente instrucción.

## 11.2 Búsqueda rápida y edición a escala (2026-08-07)

`controllers/front/products.php` ahora acepta `?reference=` (lookup O(1) exacto, incluye inactivos), `?search=` (nombre/ref/EAN vía `Product::searchByName`, sin precio real en el resultado), y `?category_id=` (filtra el listado paginado). Antes solo existía paginación cruda, inviable para catálogos de miles de productos. `AGENTS.md` documenta esto en la sección 6.1/6.2, junto con reglas de SEO/profesionalismo (6.3) y estrategia de progreso sin repetir productos.

## 11.3 Bugs reales confirmados por otro agente (2026-08-07), corregidos

Reporte del usuario tras probar con otro agente: descuentos no soportados (ya documentado, sin cambio), "combinaciones rechazadas incluso con IDs reales", "images.add rechazado con 'Invalid canonical image add'". Investigación:

* **Combinaciones — bug real confirmado**: `AiBridgeCombinationCreateHandler::hasStableDefault()` exigía que el producto YA tuviera una combinación marcada `default_on`. Para la primera combinación de un producto (cero combinaciones), esto es imposible — bloqueaba `combinations.create` siempre, sin importar los ids. **Corregido**: si el producto no tiene combinaciones, la nueva se acepta y se marca automáticamente como default vía `Product::setDefaultAttribute()`. `verify()`/`expectedState()`/rollback actualizados para reflejar esto. Probado en vivo: primera combinación (azul, default) + segunda (verde, no default) + stock, todo correcto.
* **Imágenes — bug real confirmado**: `AiBridgeImageHandler::normalizeCanonical()` y `applyAdd()` rechazaban incondicionalmente cualquier `images.add` con `cover: true`, mientras que el preview (`normalize()`) sí lo aceptaba y hasta mostraba `"proposed_cover":"new_staged_image"` — es decir, prometía algo que la ejecución siempre rechazaba. **Corregido**: `cover` en `add` ahora se aplica de verdad (con snapshot del cover anterior para poder revertirlo si algo falla después en la misma solicitud). Probado en vivo: imagen subida con `cover:true` en un solo paso, confirmado en el producto.
* **Mensajes de error genéricos — mejora de diagnosticabilidad**: `"Invalid canonical image add."` no decía POR QUÉ. Se agregó `AiBridgeImageHandler::diagnose()` que revisa paso a paso (token no encontrado/expirado/consumido, formato de `legend`, formato de `cover`, campo no soportado) y devuelve la razón específica en `changes[].validation.errors` del preview — sin esperar a diagnostics, porque esto ocurre antes de crear la solicitud de aprobación.

## 11.4 "Las IA no saben crear combinaciones" (2026-08-07) — bug real de límite, corregido

Otro agente probado en producto 31157 no pudo crear combinaciones de color (Blanco/Azul/Negro) descritas en el texto del producto. Causa raíz real: `AiBridgeCombinationCreateHandler::normalize()` exigía **exactamente una** combinación por llamada (`count($value['create']) !== 1`). El agente intentó pasar las 3 de una vez, fue rechazado, adivinó mal el formato (`update` en vez de `create`) y terminó bloqueado pidiendo SKUs que no existen (la referencia nunca fue obligatoria).

**Corregido**: `AiBridgeCombinationCreateHandler` ahora acepta un array de hasta 30 combinaciones por llamada (`capture`/`apply`/`verify`/`rollback` reescritos para manejar `created_ids` en vez de un solo `created_id`). La primera combinación del lote se marca automáticamente como default si el producto no tenía ninguna. Probado en vivo en el producto real 31157: 3 combinaciones (Blanco/Azul/Negro) creadas en una sola llamada sin referencia, + stock de 40 a cada una en una segunda llamada.

`AGENTS.md` sección 11 reescrita: paso 1 obligatorio `GET /attributes`, paso 2 creación en lote, aclaración explícita de que `combinations.update` es solo para editar combinaciones ya existentes (identificadas por `id_product_attribute`, no por color), y que la referencia/SKU es opcional — nunca bloquear la creación pidiéndola.

## 11.5 Auto-actualización sin desinstalar (2026-08-07)

A pedido del usuario: actualizar el módulo sin desinstalar/reinstalar, sin licenciamiento todavía (se deja para después). Implementado:

* `classes/AiBridgeSelfUpdater.php` — descarga el zip de una URL de manifiesto (HTTPS obligatorio), verifica `sha256`, hace **respaldo** de la carpeta del módulo en vivo antes de tocar nada, reemplaza archivos con `Tools::recurseCopy`, corre `Module::needUpgrade()` + `runUpgradeModule()` (las funciones core reales que corre PrestaShop al pulsar "Actualizar" en el listado de módulos), y **restaura el respaldo automáticamente si cualquier paso falla**.
* Panel nuevo en Back Office → Módulos → AI Bridge → Configurar: versión instalada, aviso si hay una nueva, botón "Actualizar ahora" (con confirmación), URL del manifiesto editable (por defecto apunta a `https://saruia.es/aibridge-releases/manifest.json`).
* Sigue siendo una acción manual con un clic del administrador — no hay cron ni actualización silenciosa en segundo plano. Decisión deliberada: mismo principio de "humano en el loop" que el resto del módulo.
* Publicado `1.13.1` en `https://saruia.es/aibridge-releases/` (manifest.json + zip) como canal de actualizaciones.

**Bloqueo real encontrado, no resuelto por mí**: `saruia.es` tiene protección Basic Auth a nivel de todo el dominio (staging de Hostinger). El propio servidor, al intentar descargar su manifiesto vía HTTPS, recibe `401` — **la auto-actualización no puede funcionar mientras esa protección siga activa**, porque bloquea también las peticiones salientes del propio sitio hacia sí mismo. Dos salidas: (a) quitar la protección de staging ahora que el sitio es de producción, o (b) mover el canal de releases (`manifest.json` + zips) a un host sin esa protección (otro subdominio, GitHub Releases, etc.), dejando la URL del manifiesto configurable como ya está.

**No pude probar el botón "Actualizar ahora" end-to-end** — requiere sesión autenticada de Back Office, y no inicio sesión con contraseña por política de seguridad. El código reutiliza las mismas funciones core (`Module::needUpgrade`, `runUpgradeModule`, `Tools::recurseCopy`) que ya usa el propio PrestaShop, pero falta la prueba real con un clic humano.

**Bloqueo de Basic Auth resuelto (2026-08-07) sin exponer la tienda**: en vez de quitar la protección de todo el dominio, se subió un `.htaccess` propio solo dentro de `aibridge-releases/` (`AuthType None`, `Require all granted`, `Satisfy any`, `Options -Indexes`) que anula la herencia del `AuthType Basic` del `.htaccess` raíz **únicamente para esa carpeta**. No se tocó el `.htaccess` raíz (gestionado por hPanel, marcador `PWPROTECTID`). Verificado: `manifest.json` y el `.zip` responden 200 sin credenciales; el resto del sitio (`/`, `/index.php?...controller=ping`) sigue en 401; listado de directorio bloqueado (403). Mismo patrón reutilizable si algún día se agregan más rutas públicas puntuales sin abrir todo el dominio.

## 11.6 Descuentos (2026-08-07)

Implementado `AiBridgeDiscountHandler.php`: gestiona una única fila `specific_price` "universal" por producto (id_shop=0, id_currency=0, id_country=0, id_group=0, id_customer=0, id_cart=0, id_specific_price_rule=0, id_product_attribute=0) — un descuento simple aplicable a todos, sin tocar otras reglas de precio que el admin haya creado manualmente. Campo `discount` en `productpreviewupdate` (`reduction_type`: percentage/amount, `reduction`, `from_quantity`, `from`/`to` opcionales; `{"active": false}` para quitar). Cableado en `AiBridgeProductPreview` y `AiBridgeApprovalExecutor` (capture/apply/verify/rollback). `GET /product` ahora expone el descuento activo con el precio ya calculado. Versión bump a `1.14.0`.

Nota de diseño: `normalize()` está hecho para ser idempotente (su salida es válida como entrada) porque `canonicalizePayload` y `buildChange` re-normalizan el mismo valor dos veces — usar `{"active": false}` en vez de `null` para "quitar" fue deliberado para no chocar con la convención existente de `normalize() === null` significando "payload inválido" en todo el resto del módulo.

Probado en vivo en producto 31157: aplicar 15% (`reduction_type: percentage, reduction: 0.15`), confirmado en `GET /product` con `price_tax_excl_after_discount` correcto, y quitarlo (`active: false`) confirmado con `discount: null`.

## 11.7 Primera prueba real de auto-actualización en otro sitio (2026-08-07) — 2 bugs reales encontrados y corregidos

* **Bug 1 — zip con separadores de ruta de Windows**: el zip de release se generaba con `Compress-Archive` de PowerShell, que guarda las entradas internas con `\` en vez de `/`. En el servidor Linux, `ZipArchive::extractTo()` de PHP no reconoce `\` como separador de carpeta, así que `aibridge\aibridge.php` se extraía como un nombre de archivo plano y raro en vez de crear `aibridge/aibridge.php`. Resultado: "El paquete no contiene un módulo aibridge válido" pese a que el zip era válido y el checksum coincidía. **Corregido**: el zip ahora se genera manualmente con `System.IO.Compression.ZipArchive` normalizando cada ruta a `/`. Verificado con `unzip -l` en Linux que la estructura queda correcta.
* **Bug 2 — `Tools::recurseCopy()` de PrestaShop no tiene `return true;` en el camino exitoso** (implícitamente devuelve `null`, que es falsy en PHP). `AiBridgeSelfUpdater` comprobaba `if (!Tools::recurseCopy(...))` para detectar fallos — como el valor de retorno exitoso también es falsy, el respaldo se declaraba fallido **siempre**, incluso cuando la copia funcionaba perfectamente. Esto bloqueaba cualquier auto-actualización real en el paso de respaldo. **Corregido**: nuevo helper `AiBridgeSelfUpdater::copyDirectory()` que ignora el valor de retorno y verifica por evidencia real (que `aibridge.php` exista en el destino después de copiar).

Ambos bugs solo podían encontrarse probando de verdad en un sitio con el módulo instalado — no aparecían en revisión de código porque el comportamiento de `Tools::recurseCopy()` (sin `return` explícito) es fácil de asumir mal, y el problema del zip solo se manifiesta al extraer en un SO distinto al que lo generó. Publicado `1.14.2` con ambos fixes; pendiente de que el usuario confirme el "Actualizar ahora" en el sitio de producción real.

## 11.8 Fase 4.1 — Fabricante en creación (2026-08-09)

Añadido `id_manufacturer` opcional a `product.create` (default `0`, sin marca). Validado con `AiBridgeClassificationHandler::validateField` (reutiliza la misma lógica que `productpreviewupdate`), aplicado en `AiBridgeProductCreateExecutor::buildProduct` y verificado en `verify()`. Probado en vivo en `saruia.es`: producto 31166 creado con `id_manufacturer:1` (marca "Studio Design"), y producto 31167 sin el campo confirmado con `id_manufacturer:0` — ambos correctos. Continúa la Fase 4 (4.2 en adelante).

## 13. Fase 2.1 del plan nuevo — Multi-empleado y memoria (2026-08-09, COMPLETADO)

A pedido del usuario (documento `AI-Bridge-Fase2-Plan.md`), implementado el primer paso hacia el agente nativo tipo Shopify: identidad por empleado y memoria de conversación. Versión `1.15.0`.

* **Tokens por empleado** (`classes/AiBridgeEmployeeToken.php`, tabla `aibridge_employee_token`, `token_hash` sha256 igual que `aibridge_upload`): cada empleado de PrestaShop puede tener su propio token API, generado/regenerado/revocado desde un panel nuevo en Back Office → Módulos → AI Bridge → Configurar ("Tokens por empleado"). El token se muestra en claro solo una vez, justo al generarlo (no se puede volver a ver).
* **Retrocompatibilidad explícita** (decisión del usuario): el token global legacy (`X-AI-Bridge-Token` único de siempre) sigue funcionando exactamente igual, resolviendo a `id_employee=0` — ninguna integración existente (saruia.es, producción real) se rompe. `AiBridge::isValidApiToken()` ahora prueba primero el token legacy y después la tabla de tokens por empleado; `AiBridge::getAuthenticatedEmployeeId()` expone el id resuelto (0 = legacy/anónimo).
* **Propagación del id resuelto**: todos los controllers que antes hardcodeaban `0` como `employeeId` (`productcreatepreview`, `productpreviewupdate`, `categorycreatepreview`, `categorypreviewupdate`, `manufacturercreatepreview`, `batchpreview`, `batchapply`) ahora usan `$this->module->getAuthenticatedEmployeeId()`, así que `created_by_employee_id`/`executed_by_employee_id` en la auditoría (`aibridge_approval_request`/`aibridge_log`) reflejan quién hizo cada cosa de verdad.
* **Memoria de conversación** (`classes/AiBridgeConversation.php`, tabla `aibridge_conversation`, un historial por `id_employee` que se sobrescribe completo — decisión del usuario de empezar simple, no un log de mensajes individuales): endpoint nuevo `controllers/front/conversation.php`, `GET` para recuperar (`{"messages": [...], "updated_at": ...}` o `null` si no hay nada guardado) y `POST` para guardar (`{"messages": [...]}`, tope 512KB). Aislado por identidad: el token legacy y cada token de empleado tienen su propio historial, nunca se mezclan.
* **Diagnosticabilidad**: `GET /diagnostics` ahora expone `authenticated_employee_id`, útil para confirmar en caliente qué identidad resuelve un token dado sin tener que provocar una escritura.
* **Deploy sin pipeline de git/upgrade automático**: como el deploy a `saruia.es` es copiar archivos por FTP (no pasa por `Module::runUpgradeModule()`), las dos tablas nuevas se crean tanto en `sql/install.php` (instalación fresca) como en `upgrade/upgrade-1.15.0.php` (sitios ya instalados, se ejecuta si algún día se dispara un upgrade real vía Back Office/auto-actualización) **y además** con un `CREATE TABLE IF NOT EXISTS` defensivo dentro de las clases mismas (`AiBridgeEmployeeToken`/`AiBridgeConversation`), para que la función ande sin depender de que el mecanismo de upgrade de PrestaShop se dispare.

Bug real encontrado y corregido durante la prueba: `AiBridgeConversation::save()` rechazaba `id_employee = 0` (`$employeeId <= 0`), lo cual rompía el guardado de memoria para el token legacy (que resuelve a 0 a propósito). Corregido a `$employeeId < 0`.

Validado end-to-end en `saruia.es`: token legacy sigue funcionando igual (`authenticated_employee_id:0`) tras el deploy; el usuario generó un token real desde el Back Office para el empleado id 2, confirmado por `diagnostics` (`authenticated_employee_id:2`); memoria de conversación probada aislada entre el token legacy y el del empleado 2 (cada uno ve solo lo suyo); una creación de producto real con el token del empleado 2 quedó registrada en la auditoría con `executed_by_employee_id:2`.

**Pendiente de la Fase 2 (siguiente en orden recomendado por el usuario):** 2.2 (pedidos y clientes), 2.3 (correos con plantillas), 2.5 (chat nativo en Back Office), 2.4 (tareas programadas/impresora), 2.6 (permisos y escalado). Ver `AI-Bridge-Fase2-Plan.md` para el detalle de cada una.

## 14. Fase 2.5 del plan nuevo — Chat nativo en Back Office (2026-08-10, v1 COMPLETADO)

A pedido del usuario, adelantada la Fase 2.5 (antes de 2.2/2.3) porque quería la interfaz de chat lista primero para probar ahí encima pedidos/clientes/correos más adelante. Versión `1.16.0`.

**Qué es v1 — un relay persistido, no un agente autónomo todavía** (decisión explícita del usuario: por ahora conectar con "el Codex/agente que esté conectado" en cada sesión de trabajo; una integración con API key propia para respuesta 100% autónoma queda para más adelante):

* **Widget flotante** (`views/js/aibridge-chat-widget.js` + `.css`): burbuja circular "AI" abajo a la derecha, visible en **cualquier página del Back Office**, inyectada vía hook `displayBackOfficeHeader`. Panel de chat con historial + textarea, sin streaming (a propósito, decisión del usuario), con polling cada 5s mientras está abierto para detectar respuestas nuevas sin recargar.
* **Backend** (`controllers/admin/AdminAiBridgeChatController.php`, ajax-only, `AdminAiBridgeChat`): usa la sesión de empleado ya autenticada del Back Office (`Context::getContext()->employee->id`), lee/escribe sobre la MISMA tabla `aibridge_conversation` de la Fase 2.1 — es decir, el historial del widget y el historial que ve un agente externo vía `GET/POST /conversation` con el token de ese empleado son literalmente el mismo dato. Por eso un agente externo (Codex, Claude, quien sea) puede leer lo que el empleado escribió en el widget y responder posteando ahí, y el widget lo recoge solo por el polling.
* **Ejecución directa, sin aprobación intermedia dentro del chat** (decisión explícita del usuario): cuando el agente que responde decide actuar (crear/editar un producto, etc.), lo hace llamando a la API normal del módulo; si `direct_apply_test_mode` está activo se ejecuta directo, si no, sigue el flujo preview→aprobación como el resto del módulo — no se agregó ningún bypass nuevo de aprobación específico del chat, se apoya en lo que ya existe.
* **Respuestas breves** (decisión explícita del usuario): quien responda por este canal debe limitarse a decir qué hizo, no explicar de más — documentado en `AGENTS.md`.
* **Auto-registro defensivo**: como el deploy a `saruia.es` es FTP directo (no dispara `Module::runUpgradeModule()`), el tab `AdminAiBridgeChat` y el hook `displayBackOfficeHeader` se registran solos la primera vez que un admin abre Módulos → AI Bridge → Configurar (`AiBridge::ensureChatWidgetInstalled()`), sin depender de que el mecanismo de upgrade de PrestaShop se dispare. Mismo patrón que el `CREATE TABLE IF NOT EXISTS` defensivo de la Fase 2.1.

Validado end-to-end en `saruia.es` con el usuario: burbuja visible en cualquier página tras abrir Configurar una vez; mensaje enviado desde el widget (empleado id 2) leído por mí vía `GET /conversation` con su token; respuesta posteada por mí vía `POST /conversation`; el widget la mostró solo, sin recargar, por el polling.

**Limitación real, no un bug**: esto funciona mientras haya una sesión de agente (como esta) activa y alguien le pida "revisá el chat". No hay todavía un proceso que responda solo, 24/7, sin que un humano dispare una sesión de Codex/Claude — eso es justamente lo que la futura integración con API key (Fase 2 "más adelante", con proveedor de IA propio) resolvería, corriendo el loop de tool-calling directamente en el servidor.

**Pendiente / no resuelto todavía**:
* El nuevo tab `AdminAiBridgeChat` queda visible en el submenú de Módulos (mismo patrón que `AdminAiBridgeApprovals`) aunque es solo un endpoint ajax — cosmético, no bloqueante.
* Permisos: el controller ajax no tiene control de acceso propio más allá del login de Back Office estándar de PrestaShop — un empleado sin perfil SuperAdmin podría no tener acceso al tab según el ACL de su perfil. No probado con un empleado de perfil limitado.
* Sin límite de longitud de conversación visible en el widget (se recorta a los últimos 200 mensajes en el backend, pero el widget siempre re-renderiza todo lo que recibe).

## 15. Fase 2.2 del plan nuevo — Pedidos, clientes y direcciones (2026-08-10, COMPLETADO)

Versión `1.17.0`, sin cambios de esquema (pedidos/clientes son solo lectura sobre tablas propias de PrestaShop; direcciones crear/editar reutilizan las columnas `product_id`/`created_product_id` de `aibridge_approval_request`, mismo patrón de deuda técnica ya usado para categorías/marcas).

* **`GET /orders`** — listado paginado (`?status_id=`, `?customer_id=`) o detalle completo con `?id=` (cliente, dirección de envío/facturación, productos, historial de estados). Solo lectura, sin aprobación (no modifica nada).
* **`GET /customers`** — `?search=` (nombre/email, con fallback a teléfono si no hay match) o `?id=` para detalle con sus direcciones y cantidad de pedidos.
* **`POST /addresscreatepreview`** y **`POST /addresspreviewupdate?id=<id>`** — crear/editar direcciones de cliente, mismo flujo preview→aprobación→ejecución→auditoría que el resto del módulo. Reutiliza `Address::$definition` dinámicamente (tamaño/validador por campo) igual que se hizo para los campos de texto/SEO de producto en la Fase 4.2.

Dos bugs reales encontrados y corregidos durante la prueba en vivo:
* **Mismo bug de idempotencia que en `images` (Fase 4.3)**: el executor de creación de dirección vuelve a correr `AiBridgeAddressCreatePreview::build()` sobre el payload ya canonicalizado, pero ese payload incluye `shop_id`/`language_id` (que `build()` recibe como parámetros separados, no como claves del payload) → `Unsupported field.` siempre. Corregido quitando esas dos claves antes de re-validar en el executor.
* **España exige DNI**: `Address::validateFields()` rechazaba la creación con `"La propiedad Address->dni está vacía."` porque este país tiene `need_identification_number` activo. El preview no lo estaba pidiendo. Corregido: `AiBridgeAddressCreatePreview` ahora exige `dni` cuando `$country->need_identification_number` es verdadero (mismo patrón condicional que ya existía para `id_state`/`contains_states`). Documentado en `AGENTS.md` para que el agente no se sorprenda si un país lo requiere.

Validado end-to-end en `saruia.es` con datos reales: pedido 11 (Christian Cedeño, 34 líneas de producto) leído completo; búsqueda de cliente "Alexander" y detalle del cliente 3 con sus 4 direcciones; edición de la dirección 19 (alias) confirmada y revertida; creación de la dirección 23 para el cliente 3 con DNI, confirmada por lectura.

**Pendiente de la Fase 2** (orden del usuario: seguir con lo que falte de 2.2/2.3, correos con plantillas): `email_templates.list/create`, `emails.send` (Fase 2.3) — no implementado todavía.

## 16. Qué darle a otro Codex/agente para que responda por el chat de Back Office

Instrucciones mínimas para que **cualquier** sesión de Codex/Claude/otro agente pueda leer y responder el chat nativo del Back Office (Fase 2.5) igual que se hace por consola:

1. **URL base** de la tienda (`https://saruia.es` en pruebas).
2. **El token del empleado correspondiente** (Back Office → Módulos → AI Bridge → Configurar → "Tokens por empleado" → Generar, si no lo tiene ya). Cada empleado tiene su propio chat/memoria — el token determina de quién lee/responde.
3. **El archivo `AGENTS.md` de este módulo** (pegarlo entero en el primer mensaje, o darle la URL/ruta si el agente puede leer archivos) — ahí está todo el protocolo: cómo autenticarse, qué endpoints existen (catálogo, pedidos, clientes, direcciones), y la sección "Chat nativo en Back Office" con el procedimiento exacto: `GET /conversation` para ver si hay un mensaje sin responder, actuar, `POST /conversation` con la respuesta.
4. **Decirle explícitamente**: "respondé solo con el resultado, sin explicaciones" (ya está en AGENTS.md sección 1, pero conviene repetirlo) y "ejecutá directo, no dejes cosas pendientes de aprobación en este canal" (coherente con `direct_apply_test_mode`; si ese checkbox está apagado, el agente debe decir en el chat que quedó pendiente de aprobación en vez de fingir que ya se aplicó).
5. Si el humano quiere que seguirle el ritmo al chat "solo", tiene que pedirle a esa sesión que revise el chat cada tanto — no hay todavía un proceso 24/7 automático (ver limitación real de la Fase 2.5, sección 14).

## 17. Fase 2.5.x — Botón "empezar de nuevo" (2026-08-10, COMPLETADO) y roadmap de multi-chat + API key propia (futuro, NO implementado)

Versión `1.17.1`. A partir de la captura de pantalla del usuario abriendo la pestaña "AI Bridge Chat" del menú (que hoy está vacía a propósito, es solo el endpoint ajax del widget, no una página) surgió la visión completa de hacia dónde va esto:

**Hecho ahora (rápido y de bajo riesgo):**
* Botón ↻ en el header del widget — borra el historial actual (`AiBridgeConversation::delete()`) y arranca de cero. `ajaxProcessResetConversation` en `AdminAiBridgeChatController`.
* Bug de caché encontrado y corregido: el navegador servía el JS/CSS viejo del widget sin recargar tras el deploy por FTP. Se agregó `?v=<version>` a las URLs de los assets en `hookDisplayBackOfficeHeader()` para que cada bump de versión fuerce una descarga fresca — sin esto, cualquier cambio futuro al widget requeriría pedirle al usuario Ctrl+F5 manualmente (como pasó acá).

**Pendiente — decisión consciente de NO improvisarlo, es una feature real, no un ajuste chico:**
* **Historiales múltiples por empleado** ("varios chats para tener distintas conversaciones"): hoy `aibridge_conversation` es una fila por empleado que se sobrescribe completa (decisión explícita de la Fase 2.1, pensada para "un solo hilo que se retoma"). Pasar a múltiples hilos con nombre, que se puedan crear/eliminar/renombrar, requiere: nueva tabla (`aibridge_conversation_thread`: id, id_employee, title, created_at, updated_at) + mover los mensajes a esa relación (o a filas propias tipo log) + UI de lista de hilos en el widget (y ahí sí la pestaña "AI Bridge Chat" del menú deja de estar vacía: se convierte en la página de gestión — ver/borrar/renombrar hilos, quizás desde el Back Office en vez de o además del widget).
* **API key propia (Claude/ChatGPT) para uso interno pagado**: el punto de conexión natural es reemplazar el "relay" actual (un agente externo como esta sesión lee/escribe la conversación por API) por un loop de tool-calling corriendo en el propio servidor PHP, usando la API key que la empresa pague. Esto es justo lo que se dejó explícitamente para "más adelante" al planear la Fase 2.5 (ver sección 14) — sigue siendo la pieza más grande de todo el proyecto: requiere un motor de function-calling en PHP contra las herramientas ya expuestas (catálogo, pedidos, clientes, direcciones) y decidir permisos/límites de gasto por empleado.
* Estas dos piezas están relacionadas pero son independientes: se puede construir multi-chat sin API key propia (sigue funcionando como relay, solo que el empleado puede tener varias conversaciones paralelas), y viceversa.

Cuando se decida encarar esto, conviene retomarlo como su propia sesión de trabajo dedicada (no como un agregado rápido a mitad de otra tarea) — es un cambio de esquema + UI nueva, no un fix.

## 18. Fase 2.3 del plan nuevo — Correos con plantillas (2026-08-09, código COMPLETADO — envío real bloqueado por infraestructura, no por el módulo)

Versión `1.18.0`. Tabla nueva `aibridge_email_template` (mismo patrón defensivo `CREATE TABLE IF NOT EXISTS` en la clase, por si el deploy FTP no dispara el upgrade de PrestaShop).

* **`GET /emailtemplates`** (listado) y **`GET /emailtemplates?name=<nombre>`** (detalle con `html_body` completo).
* **`POST /emailtemplatecreatepreview`** — crea una plantilla (`name`, `subject`, `html_body`, variables como `{{clave}}`), mismo flujo preview→aprobación→ejecución.
* **`POST /emailsendpreview`** — envía un correo usando una plantilla ya creada + `to` + `variables` (mapa clave→valor, sustituye `{{clave}}` en subject/body). El preview muestra `preview_subject`/`preview_html` ya renderizados, para que quien aprueba vea exactamente qué se va a mandar.

**Bug de idempotencia repetido (mismo patrón que imágenes/direcciones) y corregido desde el primer intento**: el executor recibe el payload canonicalizado con `shop_id`/`language_id` embebidos; se limpian antes de volver a llamar a `build()`.

**Bloqueo real encontrado investigando en vivo — no es un bug del módulo**: `Mail::Send()` de PrestaShop **ignora casi por completo** el parámetro `$templatePath` que se le pasa — internamente (`Mail::getTemplateBasePath()`) solo busca la plantilla en la carpeta `mails/` del tema activo, del tema padre, o (si la ruta pasada contiene literalmente `modules/<nombre>/`) en `modules/<nombre>/mails/`. Se descubrió leyendo `classes/Mail.php` real del servidor por FTP (no hay este archivo en el repo del módulo, se bajó solo para inspección, no se modificó). Corregido: el executor ahora escribe los archivos temporales de plantilla (uno por envío, se borran después) en `modules/aibridge/mails/<idioma>/`, que sí es una ruta que `Mail::Send()` reconoce.

Con eso resuelto, apareció un bloqueo distinto y real: `Mail::Send()` devuelve `false` con `Error: Call to undefined function proc_open() @ StreamBuffer.php:291` — el hosting (Hostinger) tiene `proc_open()` deshabilitado a nivel de PHP (restricción de seguridad común en hosting compartido), y `saruia.es` **no tiene un SMTP real configurado** (confirmado por el usuario), así que PrestaShop cae al envío vía `sendmail`/PHP `mail()`, que necesita `proc_open()`.

**Esto no se puede arreglar desde el código del módulo.** Para que el envío real funcione, el usuario debe configurar un servidor SMTP real en Back Office → Parámetros de la tienda → Correo (Gmail con contraseña de aplicación, SendGrid, Mailgun, o el proveedor que use) — ahí no puedo entrar yo (política: nunca inicio sesión en Back Office con contraseña). Una vez configurado el SMTP, reintentar el mismo payload de `/emailsendpreview` debería funcionar sin cambios de código.

Validado end-to-end lo que sí depende del módulo: plantilla `test-aibridge` creada y listada correctamente; preview de envío renderiza `{{firstname}}`/`{{product_name}}` bien; la ejecución llega hasta el intento real de `Mail::Send()` (confirma que routing/plantilla/permiso de escritura en `modules/aibridge/mails/` funcionan) y falla exactamente en el punto esperado por la restricción de infraestructura, con el error específico visible en `/diagnostics` en vez de un fallo silencioso.

## 19. Fase 2.5.x (continuación de la sección 17) — API key propia multi-proveedor, reemplaza el relay externo (2026-08-22, COMPLETADO)

Versión `1.19.0`. Se implementó la pieza que la sección 17 dejó pendiente: el chat de Back Office ya no depende de un agente externo (Codex u otro) sondeando `/conversation` con un token — ahora `AdminAiBridgeChatController::ajaxProcessSendMessage` llama directo, de forma síncrona, a un proveedor de IA configurado con API key propia.

* **Nuevo tab "AI Bridge → Proveedores de IA"** (`AdminAiBridgeAiProvidersController` + tabla `aibridge_ai_provider`): alta/baja de proveedores con nombre, tipo (`openai` | `anthropic` | `openai_compatible`), modelo, API key (nunca se re-muestra en el form, solo enmascarada en la lista) y, para el tipo genérico, `base_url`. Un proveedor `is_default` es el que usa el chat.
* **`openai_compatible` cubre "cualquiera"** sin código nuevo por proveedor: cualquier API que hable el formato `/chat/completions` de OpenAI (Gemini vía su endpoint OpenAI-compat, DeepSeek, Groq, OpenRouter, Ollama local, etc.) solo necesita `base_url` + `api_key`.
* **`classes/ai/`**: `AiBridgeAiClientInterface` (contrato común), `AiBridgeOpenAiClient` (sirve OpenAI y todo lo compatible), `AiBridgeAnthropicClient` (formato de tools/mensajes distinto de Anthropic), `AiBridgeAiClientFactory`.
* **Tool-calling real, no solo texto**: `AiBridgeToolRegistry` expone `product_search`, `category_list`, `brands_list` (lectura directa) y `product_create/update`, `category_create/update`, `manufacturer_create` (escritura). Los tools de escritura reusan exactamente las mismas clases `Preview` que ya usa la API HTTP y terminan creando un `AiBridgeApprovalRequest` pendiente — **nunca escriben directo**. Como `AiBridgeApprovalRequest::approve()` ya rechaza que el mismo empleado que creó la solicitud la apruebe, esto automáticamente exige que sea *otro* admin quien apruebe lo que propuso la IA del chat — no hizo falta lógica nueva para eso, ya estaba en el modelo de aprobación.
* **`AiBridgeChatOrchestrator`** corre el loop de tool-calling (máx. 5 iteraciones por turno) dentro del mismo request AJAX del chat — sin persistir el intercambio tool_call/tool_result, solo el texto final y una línea de resumen por cada tool ejecutado (ej. "🔧 product_create — propuesta creada, pendiente de aprobación (#123)"). El system prompt inyecta el contenido completo de `AGENTS.md` (tope defensivo 60000 caracteres) en vez de duplicar los esquemas de payload en el código — la guía ya escrita para agentes externos ahora también sirve de contexto al modelo interno.
* **Tools todavía no cubiertos** (quedan para una próxima pasada, mismo patrón — agregar entrada a `AiBridgeToolRegistry` + método en `AiBridgeToolExecutor`): combinaciones, imágenes, descuentos como acción aparte (hoy solo alcanzables vía `product_update` si el payload lo soporta), direcciones, emails, batch.
* **Historiales múltiples por empleado** (la otra mitad de la sección 17) sigue sin implementar — se mantiene una sola conversación por empleado que se sobrescribe.
* Tabla `aibridge_ai_provider` se crea tanto en `sql/install.php` (instalación nueva) como en `upgrade-1.19.0.php` (upgrade normal) y de forma defensiva en `ensureChatWidgetInstalled()` (deploy FTP sin upgrade automático), siguiendo el mismo patrón ya usado para las demás tablas del módulo.

## 20. Bug real del auto-updater encontrado en producción (`wephone.es`) y corregido (2026-08-22)

Versión `1.19.2`. Se confirmó en vivo, en el sitio de producción real (`wephone.es`, no `saruia.es` — primera vez que se dieron accesos a ese servidor), el riesgo que ya se sospechaba desde la sección 11.7 del `HANDOFF.md`: `restoreBackup()` hacía `Tools::deleteDirectory($liveModuleDir)` y DESPUÉS `copyDirectory($backupDir, $liveModuleDir)` — si esa segunda copia fallaba (recurseCopy no es confiable, ver bug ya documentado), la carpeta `aibridge` quedaba **completamente vacía/inexistente**, causando "Módulo no encontrado" en todo el Back Office. Pasó dos veces seguidas (`aibridge_backup_20260822134033` y `aibridge_backup_20260822135200` quedaron huérfanos sin ningún `aibridge` en vivo).

**Recuperación manual en caliente**: con acceso SFTP directo al servidor (Plesk, `82.223.0.121`), se restauró renombrando el backup más reciente de vuelta a `aibridge` (`rename` atómico vía comando `-Q` de curl SFTP) — la carpeta volvió a responder inmediatamente.

**Hallazgo de seguridad aparte, ya resuelto**: esa carpeta en `wephone.es` era una copia cruda de la carpeta local completa (el usuario confirmó: zipeó la carpeta local entera a mano para ese deploy, no usó `scripts/build_release.ps1`), incluyendo `HANDOFF.md` con las credenciales de `saruia.es` en texto plano — y estaba **públicamente descargable** (HTTP 200 en `/modules/aibridge/HANDOFF.md`). Se borró del servidor de inmediato; un proxy/caché intermedio lo sirvió en caché un rato más incluso después de borrado en origen. Se le recomendó al usuario rotar contraseña de Back Office, FTP, y token API de `saruia.es` por precaución. `.git` no estaba expuesto (403 del servidor). **Lección para futuros deploys manuales: usar siempre el zip generado por `scripts/build_release.ps1` (excluye `HANDOFF.md`, `.git`, `dist`, `graphify-out`, `scripts`), nunca comprimir la carpeta local completa a mano.**

**Fix real en `classes/AiBridgeSelfUpdater.php`**: reemplazado el patrón borrar-en-vivo-y-copiar-de-vuelta por un swap con `rename()` atómico, exactamente lo mismo que la recuperación manual que funcionó:
1. La versión nueva se copia primero a una carpeta hermana (`aibridge_incoming_<random>`) — si esta copia falla, la carpeta en vivo nunca se tocó.
2. `swapDirectories()`: `rename(aibridge, aibridge_backup_<timestamp>)` y `rename(aibridge_incoming_X, aibridge)` — dos renames en el mismo filesystem, no una copia recursiva. Si el segundo rename falla, se deshace el primero automáticamente.
3. Si falla el paso de base de datos después del swap, `swapBack()` aparta la versión fallida (`aibridge_failed_<random>`, queda para inspección) y renombra el respaldo de vuelta a `aibridge` — nunca queda sin carpeta en vivo.

`copyDirectory()` (con su limitación conocida de `Tools::recurseCopy()`) ahora solo se usa para el paso que puede fallar sin consecuencias (copiar a la carpeta hermana antes de tocar nada en vivo) — ya no está en la ruta crítica de reemplazo ni de rollback.

## 12. Notas y bloqueos

* **2026-08-06** — Revisión completa del código confirmó que el estado real iba muy por delante de este archivo (que no existía como archivo en el repo hasta hoy, solo se había compartido su contenido por chat): captura de diagnóstico de fallos (antigua Fase 1.1/1.2) y rollback (antigua Fase 3) ya estaban implementados en el código antes de esta sesión.
* **2026-08-06** — El sitio de pruebas `saruia.es` (Hostinger) tiene protección Basic Auth a nivel de servidor, independiente del token de AI Bridge y del login de PrestaShop. Cualquier llamada HTTP a la API necesita `-u usuario:contraseña` además del header `X-AI-Bridge-Token`.
* **2026-08-06** — Bloqueo de `product.create` resuelto (ver sección 5). Costó 3 iteraciones de despliegue vía FTP porque el error real estaba siendo silenciado por el sanitizador de mensajes (`safeError()`), que existe a propósito para no filtrar detalles internos a la respuesta pública de la API. La solución fue capturar el detalle crudo de la excepción (clase, mensaje, archivo, línea) únicamente en `aibridge_log.error_message` (protegido por el mismo token vía `/diagnostics`), sin tocar el mensaje "seguro" que ve el caller de la API.
* **2026-08-06** — Detectado (no corregido, no bloqueante) un bloque de código muerto en `AiBridgeApprovalExecutor::validatePayload()`: hay un segundo `if ($field === 'combinations')` (líneas ~433-437) que nunca debería ejecutarse porque el primero ya maneja el campo con `continue` en el caso válido; en el caso inválido cae al bloque final `throw new Exception('Invalid approved payload.')` igualmente, así que no cambia el comportamiento observable, pero conviene limpiarlo en algún momento.
* **2026-08-06** — Implementado y validado end-to-end el soporte de `tags` (Fase 6.1): `classes/AiBridgeTagHandler.php` nuevo, cableado en `AiBridgeProductPreview` y `AiBridgeApprovalExecutor` (capture/apply/verify/rollback siguiendo el mismo patrón que `features`). Probado contra `saruia.es` sobre el producto de prueba 31154: primera llamada devolvió `changed: true` y aplicó los tags; segunda llamada con el mismo payload devolvió `changes: []`, confirmando que la lectura (`Tag::getProductTags`) y la escritura (`Tag::addTags` / `Tag::deleteProductTagsInLang`) son consistentes entre sí.
* **2026-08-06** — Categorías reutilizan las columnas `product_id`/`created_product_id` de `aibridge_approval_request` para guardar el id de categoría (distinguido por `operation_type`). Es deuda técnica consciente para no tocar el schema — si en el futuro se agregan más tipos de entidad, considerar columnas `target_entity_type`/`target_entity_id` genéricas.
* **2026-08-06** — Despliegue de este módulo a `saruia.es` es manual vía FTP (`92.113.28.74`, carpeta `domains/saruia.es/public_html/modules/aibridge/`). No hay pipeline de git ni sincronización automática — cualquier cambio local en esta carpeta necesita subirse archivo por archivo (o con un cliente FTP que sincronice el árbol) antes de que exista en el sitio real.
