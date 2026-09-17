# WP2Shell-Vax 💊

**Mitigación temporal para wp2shell en WordPress.** Plugin convencional instalable mediante ZIP, por Kopernix. Licencia GPL-2.0-or-later.

> **No es un parche del core ni un antivirus. Actualiza WordPress: 6.8.6, 6.9.5, 7.0.2 o versiones posteriores de las respectivas ramas.** La mitigación de este plugin se limita a restringir la ruta REST `/batch/v1` y a evitar la ejecución del vector wp2shell en versiones conocidas vulnerables.

## Instalar (sin SSH)

1. Descarga el archivo `wp2shell-vax.zip` desde Releases (o genera el ZIP con la carpeta `wp2shell-vax/`).
2. WordPress → Plugins → Añadir plugin → Subir plugin → selecciona el ZIP → Instalar → Activar.
3. Si el WordPress es vulnerable a wp2shell, la protección se activa automáticamente, sin configuración.
4. En **Herramientas → WP2Shell-Vax**, consulta la versión, el estado, el modo estricto y el registro opcional.

No requiere servicios externos ni modificar `wp-config.php`. Incluye `Update URI: false` para impedir que un plugin homónimo de WordPress.org lo sobrescriba; las actualizaciones de WP2Shell-Vax deben gestionarse desde tu propio flujo de publicación o desde la instalación del ZIP.

## Versiones y comportamiento

| WordPress | Diagnóstico | Comportamiento |
| --- | --- | --- |
| 6.9.0–6.9.4, 7.0.0–7.0.1 y 7.1 beta 1 | Cadena wp2shell | Restringe REST batch inmediatamente. |
| 6.8.0–6.8.5 | Solo SQLi CVE-2026-60137 | No intenta mitigar esa SQLi; advierte y se desactiva en el siguiente acceso de un administrador. Actualiza a 6.8.6+. |
| 6.8.6+, 6.9.5+, 7.0.2+ y ramas posteriores | No afectadas por esta cadena según las correcciones publicadas | No filtra peticiones; se auto-desactiva en la próxima petición al panel hecha por un administrador. |
| Versión no interpretable | Indeterminada | Bloquea preventivamente batch y no se auto-desactiva hasta resolver el estado. |

La protección deja de aplicarse **inmediatamente** cuando el core ya no pertenece a una versión afectada. La **desactivación del plugin** requiere una visita posterior al panel por alguien con permisos de administración.

## Política de bloqueo

- **Normal (predeterminada):** deniega todas las peticiones anónimas al endpoint REST que WordPress haya resuelto como `/batch/v1`; responde 401. Deja pasar a usuarios autenticados.
- **Estricto (opcional):** deniega todas las peticiones al mismo endpoint, también autenticadas; responde 403. Podría afectar al editor de bloques, plugins o integraciones que utilicen batch. No es una solución de seguridad general.
- Dos comprobaciones: `rest_pre_dispatch` y `rest_request_before_callbacks`. Nunca interpreta payloads de ataque ni confía en la URL cruda. No promete impedir la ejecución de otro plugin que dependa de `batch/v1`.
- No añade reglas para `/wp/v2`, WooCommerce ni otros endpoints y no interfiere con rutas normales.

## Registro opcional de IPs (apagado por defecto)

Desde **Herramientas → WP2Shell-Vax** activa “Store blocked REMOTE_ADDR IPs”. Se crea una tabla por sitio (`{prefix}wp2shell_vax_blocks`) solo al activarlo. La tabla muestra hasta 100 IPs recientes y la opción de borrarlas manualmente.

- Únicamente usa `REMOTE_ADDR` y valida IPv4/IPv6; ignora `X-Forwarded-For`/`CF-Connecting-IP` para no aceptar cabeceras falsificables. Si hay proxy, verás la IP del proxy. No se guardan cuerpos de request ni payloads.
- Aproximadamente una muestra/IP/hora, con presupuesto aproximado de 20 muestras por hora para todo el sitio. Los transient no son contadores atómicos y la concurrencia puede sobrepasar ligeramente ese límite.
- Máximo 500 IPs únicas (descarta primero las menos recientes); elimina filas cuya última observación tenga más de 30 días por WP-Cron y al entrar en la pantalla de WP2Shell-Vax. WP-Cron depende del cron de WordPress.
- Botón **Delete all IP logs now** en el panel. Los registros se **borran automáticamente al desactivar el plugin**, también cuando este se desactiva tras parchear WordPress; si quieres conservarlos, debes exportarlos antes.
- La IP es dato personal en muchos contextos: informa según tus políticas y habilita el registro solo si lo necesitas.

Aunque está limitado, habilitar logs introduce escrituras y consultas de BD bajo ataque; si sufres un volumen grande, déjalos apagados y utiliza logs/rate limiting del reverse proxy o WAF.

## Verificación recomendada en staging

1. En un entorno aislado con WordPress 6.9.4 o 7.0.1: probar `POST /wp-json/batch/v1` anónimo y `POST /?rest_route=/batch/v1` anónimo; deben devolver 401.
2. Confirmar que rutas REST no batch siguen funcionando; probar batch autenticado en modo normal y estricto (403).
3. Verificar que el editor y plugins no sufren regresiones; comprobar la representación real de la ruta REST en entornos multisite/subdirectorios.
4. Actualizar WordPress y verificar que el filtro queda inactivo y que el plugin se desactiva en la próxima visita de un administrador (y borra las IP guardadas).
5. Si existe evidencia de compromiso, investigar usuarios administradores, archivos, plugins, temas y datos; WP2Shell-Vax **no limpia compromisos previos**.

### Pruebas offline incluidas en el repositorio

`php tests/smoke.php` (usa dobles de WordPress; **no** sustituye pruebas de integración en WordPress real). `php -l wp2shell-vax.php` para sintaxis.

## Multisite

Se admite instalación por sitio; la activación **a nivel de toda la red está deshabilitada** intencionadamente para evitar que una sola instancia gestione equivocadamente opciones y registros de una red completa.

## Referencias y créditos

- Equipo de seguridad de WordPress (parches): https://wordpress.org/news/2026/07/wordpress-7-0-2-release/
- Descubrimiento y mitigación provisional, Searchlight Cyber (Adam Kues): https://wp2shell.com/
- API REST, `rest_pre_dispatch`: https://developer.wordpress.org/reference/hooks/rest_pre_dispatch/

WP2Shell-Vax es una implementación independiente. **No afiliado a WordPress, ni Searchlight Cyber.** El nombre es una denominación de software, no un producto sanitario ni una garantía de seguridad.

## Licencia

GPL-2.0-or-later, véase `LICENSE`.
