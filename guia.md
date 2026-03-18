# Guía de integración: autenticación por certificado (HawCert)

Esta guía describe **cómo integrar** la autenticación que hemos implementado en HawCert en otras plataformas **sin ejemplos de código**, únicamente:

- Endpoints disponibles
- Formatos de petición (JSON)
- Respuestas esperadas (JSON)
- Reglas de validación, seguridad y funcionamiento

> Base URL: la de tu instalación de HawCert (p. ej. `https://hawcert.hawkins.es`).

---

## 1) Conceptos (qué valida el sistema)

- **Certificado**: entidad registrada en HawCert (con usuario asociado, servicios permitidos y permisos).
- **Servicio (`service_slug`)**: “área” o plataforma destino a la que el certificado puede acceder (p. ej. `ionos`, `portal-x`, etc.). Se controla por asignación del certificado al servicio.
- **Identificador de login efectivo**:
  - HawCert puede devolver el **email del certificado** o, si existe, un **usuario personalizado por servicio** (`auth_username`) configurado en la asignación certificado↔servicio.
  - Cuando veas `user.email` o `certificate.email` en respuestas de validación, **ese valor es el que debe usar el sistema destino para autenticar/identificar**.
- **Key de acceso (Access Key)**: token de corta vida y **de un solo uso** que una plataforma destino puede validar en HawCert para confirmar acceso.
- **Credenciales (para extensión / autofill)**:
  - `auth_type = form`: hay usuario/contraseña (y selectores) para completar formularios.
  - `auth_type = certificate_only`: no hay usuario/contraseña; la extensión debe avisar al usuario que use el certificado del navegador.

---

## 2) Endpoints (API)

En `routes/api.php` existen estos endpoints (todos `POST`):

- `/api/validate-certificate`
- `/api/validate-access`
- `/api/validate-key`
- `/api/get-credentials`

Formato general de respuesta (tanto éxito como error):

- `success`: boolean
- `message`: string (en errores)

---

## 3) Validar certificado por “certificate_key”

### Endpoint
`POST /api/validate-certificate`

### Uso típico
Cuando una plataforma externa ya dispone de un **`certificate_key`** (por ejemplo, un identificador/clave entregada a esa plataforma) y quiere verificar:

- que el certificado existe
- que está activo/no expirado
- que tiene acceso al `service_slug`

### Request (JSON)

- `certificate_key` (string, **obligatorio**)
- `service_slug` (string, **obligatorio**)

### Respuesta (200 OK)

- `success`: `true`
- `access_token`: string (token temporal, actualmente cacheado con duración 24h)
- `expires_at`: ISO-8601 string
- `user`:
  - `id`: number
  - `name`: string
  - `email`: string (**identificador efectivo**: `auth_username` del servicio si existe; si no, email del certificado)
- `permissions`: string[] (slugs)
- `certificate`:
  - `id`: number
  - `name`: string
  - `email`: string (email propio del certificado)
  - `valid_until`: ISO-8601 string | `null`
  - `never_expires`: boolean

### Errores esperados

- **404**: certificado no encontrado
  - `success=false`, `message="Certificado no encontrado"`
- **403**: certificado inválido/expirado o sin acceso al servicio
  - `message`:
    - `"Certificado inválido o expirado"`
    - `"El certificado no tiene acceso a este servicio"`
- **422**: validación de request (faltan campos / tipo incorrecto)

### Registro de uso
Este endpoint registra un log (`event_type = validation`) para auditoría.

---

## 4) Validar acceso con certificado PEM y obtener una key (recomendado para “SSO”/puerta de entrada)

### Endpoint
`POST /api/validate-access`

### Uso típico
Flujo recomendado cuando una plataforma (o un componente intermedio) dispone del **certificado en formato PEM** y quiere obtener una **Access Key** que luego será consumida por un servidor destino para permitir acceso:

1) Cliente (o integrador) llama a `/validate-access` con el PEM + la URL destino.
2) HawCert valida y devuelve `access_key` con expiración.
3) El servidor destino llama a `/validate-key` (una vez) para comprobar y consumir la key.

### Request (JSON)

- `certificate` (string PEM, **obligatorio**)
- `url` (string URL, **obligatorio**)  
  Debe ser la URL del servicio destino (se usa para inferir el host/servicio y para vincular la key a una URL).
- `service_slug` (string, opcional)  
  Si no se envía, HawCert intentará **inferir el servicio desde la URL**.

### Respuesta (200 OK)

- `success`: `true`
- `access_key`: string (formato `ak_` + 48 chars; longitud total 51)
- `expires_at`: ISO-8601 string
- `service`:
  - `name`: string
  - `slug`: string
- `user`:
  - `id`: number
  - `name`: string
  - `email`: string (email del certificado)
- `certificate`:
  - `id`: number
  - `name`: string
  - `email`: string
- `permissions`: string[]

### Errores esperados

- **400**:
  - certificado no parseable: `"Certificado inválido o no se pudo parsear"`
  - no se puede determinar servicio: `"No se pudo determinar el servicio desde la URL"`
- **403**:
  - certificado inválido/expirado: `"Certificado inválido o expirado"`
  - sin acceso al servicio: `"El certificado no tiene acceso a este servicio"`
- **404**:
  - certificado no encontrado: `"Certificado no encontrado en el sistema"`
  - servicio no encontrado/inactivo: `"Servicio no encontrado o inactivo"`
- **422**: validación de request (faltan campos / tipo incorrecto)
- **500**: error inesperado procesando solicitud

### Seguridad (importante)

- La key generada se ata a una `target_url` (la enviada en la petición).
- En `/validate-key` se exige **coincidencia estricta del host** entre:
  - `target_url` guardada en la key
  - `url` que el servidor destino envía al validar

---

## 5) Validar/consumir una key (servidor destino)

### Endpoint
`POST /api/validate-key`

### Uso típico
Este endpoint lo consume **la plataforma/servidor destino** para aceptar una key y convertirla en una sesión/autenticación local.

Propiedades clave:

- **Un solo uso**: la key se marca como usada **antes** de responder.
- **Control por host**: solo se acepta si el host de `url` coincide con el host de la URL asociada a la key.

### Request (JSON)

- `key` (string, **obligatorio**, tamaño exacto 51)
- `url` (string URL, **obligatorio**)  
  Debe ser la URL del recurso/servicio que está validando (se usa para comprobar host).

### Respuesta (200 OK)

- `success`: `true`
- `valid`: `true`
- `certificate`:
  - `id`: number
  - `name`: string
  - `common_name`: string | `null`
  - `email`: string (**identificador efectivo**: `auth_username` del servicio si existe; si no email del certificado)
- `user`:
  - `id`: number
  - `name`: string
  - `email`: string (**mismo identificador efectivo**)
- `service`:
  - `slug`: string | `null`
- `permissions`: string[]
- `expires_at`: ISO-8601 string

### Errores esperados

- **404**: key inexistente
  - `"Key de acceso no encontrada"`
- **403**:
  - key expirada o ya usada:
    - `"Key de acceso ya fue utilizada"`
    - `"Key de acceso ha expirado"`
    - `"Esta key ya fue utilizada"` (si detecta reutilización)
  - key sin URL destino: `"Key inválida: sin URL destino"`
  - URL/host no coincide: `"La key no es válida para esta URL"`
  - certificado asociado inválido: `"El certificado asociado a esta key ya no es válido"`
- **422**: validación de request (faltan campos / tipo incorrecto)
- **500**: error inesperado procesando validación

### Registro de uso
Este endpoint registra un log (`event_type = key_validation`) siempre que la key sea validada correctamente.

---

## 6) Obtener credenciales para autofill (extensión / automatización)

### Endpoint
`POST /api/get-credentials`

### Uso típico
La extensión/cliente que tiene acceso al **certificado PEM** solicita, para la URL actual:

- si existe una credencial `form` (usuario/contraseña + selectores)
- o si es `certificate_only` (solo certificado, sin campos que rellenar)

Además, el sistema puede devolver credenciales **“generales”** (no ligadas a usuario/certificado) si no hay una credencial específica que aplique.

### Request (JSON)

- `certificate` (string PEM, **obligatorio**)
- `url` (string URL, **obligatorio**)
- `manual` (boolean, opcional)  
  Debe ser `true` **solo** cuando la obtención es por acción explícita del usuario (p. ej. clic en “Rellenar ahora”).  
  Si no se envía, se considera `false`.

### Respuesta (200 OK)

- `success`: `true`
- `credential`:
  - `id`: number
  - `website_name`: string
  - `certificate_only`: boolean

Si `certificate_only = false` (modo `form`), además:

- `username_field_selector`: string | `null`  
  Si es `null`, el cliente puede usar “detección automática” del campo.
- `password_field_selector`: string | `null`
- `submit_button_selector`: string | `null`
- `username`: string | `null`
- `password`: string | `null`
- `auto_fill`: boolean
- `auto_submit`: boolean (actualmente siempre `true` en la respuesta)

Si `certificate_only = true`:

- No se incluyen `username/password/selectores` (la UX recomendada es notificar al usuario que debe autenticarse con certificado del navegador).

### Errores esperados

- **400**: certificado no parseable
  - `"Certificado inválido o no se pudo parsear"`
- **403**: certificado inválido/expirado
  - `"Certificado inválido o expirado"`
- **404**:
  - certificado no encontrado: `"Certificado no encontrado en el sistema"`
  - no hay credencial para la URL: `"No se encontraron credenciales para esta URL"`
- **422**: validación de request (faltan campos / tipo incorrecto)
- **500**: error inesperado

### Registro de uso (muy importante)
Este endpoint **solo** registra log (`event_type = credentials`) si `manual=true`.  
Navegación/escaneo automático sin interacción del usuario **no** debe registrar uso.

---

## 7) Reglas de funcionamiento que debe respetar la plataforma integradora

- **Validación de host (keys)**: al validar una key, la plataforma destino debe enviar una `url` cuyo host corresponda al host real del servicio, porque HawCert lo compara estrictamente con el host de la `target_url` de la key.
- **Keys de un solo uso**: la plataforma destino debe diseñar el flujo asumiendo que la key:
  - se consume una vez
  - puede expirar
  - no puede “reintentar” con la misma key si la consumió ya
- **Identificador efectivo**:
  - usar `user.email` / `certificate.email` de las respuestas de validación como “login” efectivo si tu sistema lo requiere (puede ser `auth_username` específico del servicio).
- **Credenciales `certificate_only`**:
  - no hay usuario/contraseña que rellenar
  - la autenticación debe depender del certificado del navegador (o del mecanismo de cliente mTLS del entorno)
  - el cliente debería mostrar un aviso claro (p. ej. “Este sitio usa solo certificado”)
- **Credenciales `form` y selectores opcionales**:
  - los selectores pueden venir `null`: el cliente debe poder buscar el campo automáticamente o aplicar heurísticas.

---

## 8) Tabla rápida de estados HTTP

- **200**: operación exitosa (`success=true`)
- **400**: request válida en JSON pero datos no procesables (certificado no parseable, no se puede inferir servicio, etc.)
- **403**: autenticación/autorización denegada (certificado/key inválidos, acceso no permitido, host no coincide)
- **404**: recurso no encontrado (certificado/key/servicio)
- **422**: validación de entrada (campos requeridos, formatos)
- **500**: error interno

