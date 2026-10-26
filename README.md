# Factura Fácil

Aplicación web en español para administrar productos y clientes, preparar facturas con IVA del **10,5% y 21%**, y emitir comprobantes electrónicos mediante **ARCA WSFEv1**. PHP 8.3, SQLite y JavaScript sin dependencias de Composer ni de Node para ejecutar la aplicación.

## Funciones

- Productos: alta, consulta, búsqueda, modificación y archivo; código, nombre, descripción, precio e IVA por producto.
- Clientes: alta, consulta, búsqueda, modificación y archivo; identidad, condición de IVA, domicilio y contacto. Consulta de datos de identidad y domicilio al Padrón A13 por documento.
- Facturación: vista previa, borrador individual y creación de un borrador por cliente en una selección de hasta 100 clientes. Selección de borradores para emisión individual o masiva, en solicitudes de hasta 25 comprobantes procesados secuencialmente.
- IVA: ambas alícuotas en una misma factura; precios con IVA incluido o precios netos según configuración. Los importes se calculan en centavos enteros y las cantidades admiten tres decimales.
- Configuración: datos del emisor, punto de venta, condición fiscal, valores predeterminados, ambiente ARCA, certificado y clave privada.
- Comprobantes: estados de borrador, procesamiento, autorizado, rechazado y resultado incierto; CAE, vencimiento, observaciones y conciliación. Impresión de facturas autorizadas desde el navegador.
- Acceso con contraseña, protección CSRF, limitación de intentos de acceso y cambio de contraseña.

La clase de comprobante se valida según emisor y receptor: A/B para responsables inscriptos y C para monotributistas o exentos. Las facturas C no discriminan IVA. Esta versión emite **facturas por productos, en pesos argentinos, con CAE**; no incluye notas de crédito, notas de débito, exportaciones, servicios, otras monedas ni CAEA.

## Requisitos

- PHP **8.3 o superior de 64 bits**, con `pdo_sqlite`, `soap`, `openssl`, `simplexml`, `mbstring` y `ctype`.
- Apache con `mod_rewrite`, o un servidor equivalente configurado para enviar las rutas de aplicación a `public/index.php`.
- HTTPS en el sitio publicado.
- Escritura del usuario de PHP en el directorio privado de datos y salida HTTPS hacia los servicios oficiales de ARCA.
- Para emitir o consultar padrón: certificado X.509 y clave privada correspondientes al ambiente, CUIT y servicios habilitados.

## Instalación

1. Clonar el repositorio en el servidor:

   ```bash
   git clone https://github.com/osvaldoschenkel/FACTURAFACIL.git
   cd FACTURAFACIL
   ```

2. Configurar la raíz pública del dominio o subdominio en **`FACTURAFACIL/public`**. Los directorios `app`, `bin`, `tests` y los datos privados deben quedar fuera de la raíz pública. No publicar la carpeta completa del proyecto como raíz del sitio.

3. Elegir la ubicación de datos. El valor predeterminado es `private/storage`, dentro del proyecto y fuera de `public`. Para ubicarla en otro directorio, configurar una ruta absoluta en el entorno de PHP y en los comandos de mantenimiento:

   ```bash
   export FACTURAFACIL_STORAGE=/ruta/privada/facturafacil
   ```

   Usar el mismo valor para PHP web y PHP de consola. El usuario que ejecuta PHP debe poder crear y modificar ese directorio; conservar los permisos privados de archivos y carpetas.

4. Crear el administrador desde consola:

   ```bash
   php bin/install.php admin@tuempresa.com
   ```

   El instalador solicita la contraseña. No existe un registro público para crear el primer administrador desde la web.

5. Abrir el sitio, iniciar sesión y completar **Configuración** antes de crear los borradores que se emitirán.

Para probar en una computadora de desarrollo:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

Abrir `http://127.0.0.1:8080`. El servidor incorporado de PHP es para desarrollo; en un sitio publicado usar Apache/PHP-FPM o equivalente.

## Configuración y habilitación de ARCA

1. Completar razón social, CUIT válido, condición del emisor, domicilio, punto de venta y demás datos de impresión.
2. Mantener inicialmente **Homologación**. Cargar el certificado y la clave privada habilitados para ese ambiente; completar la contraseña de la clave si corresponde. Se verifica que el par coincida y que el certificado esté vigente.
3. Autorizar los servicios **`wsfe`** y **`ws_sr_padron_a13`** para el CUIT representado, según el procedimiento de ARCA de cada ambiente.
4. Probar la conexión y emitir comprobantes de prueba en homologación. Revisar receptor, clase de factura, fechas, base imponible, alícuotas, total y recuperación del CAE.
5. Para pasar a **Producción**, seleccionar ese ambiente, cargar su propio par de certificado y clave y configurar el punto de venta habilitado. Las credenciales de homologación no se reutilizan automáticamente en producción.
6. Crear borradores con la configuración de producción y revisar la selección antes de emitir. La confirmación de producción exige escribir **EMITIR**.

La consulta al Padrón A13 completa datos de identidad y domicilio cuando ARCA los devuelve. **No determina automáticamente la condición de IVA**: revisar y seleccionar ese dato en la ficha del cliente. Si la búsqueda devuelve varias personas para un DNI, es necesario identificar el CUIT correcto.

La prueba de conexión permite diagnosticar disponibilidad y acceso; no sustituye una emisión completa de homologación ni acredita por sí sola la habilitación productiva.

Documentación oficial usada para la integración:

- [Webservices de factura electrónica](https://www.arca.gob.ar/ws/documentacion/ws-factura-electronica.asp).
- [Manual WSFEv1](https://www.arca.gob.ar/ws/documentacion/manuales/manual-desarrollador-ARCA-COMPG.pdf).
- [Autenticación WSAA](https://www.arca.gob.ar/ws/documentacion/wsaa.asp).
- [Catálogo de servicios y Padrón A13](https://www.arca.gob.ar/ws/documentacion/catalogo.asp).

## Flujo de trabajo

1. Cargar productos con precio y alícuota, y clientes con documento y condición de IVA.
2. Crear una factura o seleccionar varios clientes para preparar un borrador por destinatario. Agregar productos, cantidades y fecha; revisar la vista previa.
3. Guardar los borradores. Los datos del emisor, cliente, productos e importes quedan guardados en cada factura. Cambiar después una ficha o un precio no modifica los comprobantes existentes.
4. En **Facturas**, filtrar y marcar los comprobantes a emitir. Revisar cantidad, total y ambiente; confirmar la operación.
5. Verificar el resultado por comprobante. Solo un CAE válido convierte una factura en autorizada. Imprimir desde el detalle de la factura autorizada.

Si cambia la configuración fiscal del emisor, generar nuevos borradores con esos datos antes de emitir. Se pueden archivar productos y clientes conservando los datos históricos de facturas. Solo pueden eliminarse borradores sin numeración fiscal.

## Cortes de conexión y conciliación

La aplicación guarda el número y los datos del envío **antes** de contactar a ARCA. Un corte puede dejar un comprobante en **Resultado incierto** o **Procesando** aunque ARCA lo haya autorizado. No se avanza la misma serie mientras exista ese pendiente.

Usar **Conciliar** en ese comprobante. La aplicación consulta el número en ARCA y comprueba destinatario, fecha, serie, importes y alícuotas. Si encuentra una autorización coincidente, recupera el CAE sin volver a facturar. Si ARCA informa expresamente que no existe, conserva los datos y el número para permitir un reintento. Si el número pertenece a un comprobante diferente, la aplicación no lo atribuye al borrador.

El reintento de una reserva conserva su fecha, serie, número y condición original del emisor, aunque hayan cambiado los datos actuales de configuración. ARCA decide si esa solicitud sigue siendo válida. Un rechazo fiscal definitivo libera el número reservado; un nuevo corte mantiene la necesidad de conciliar. Para emitir un borrador sin reserva, la configuración fiscal debe coincidir con la guardada en ese borrador.

Usar una sola instancia de esta aplicación por base de datos y mantener el punto de venta dedicado a ella. SQLite y el bloqueo de emisión coordinan los procesos que comparten el mismo directorio privado; no coordinan otra aplicación o servidor que emita por el mismo punto de venta.

## Respaldos

Desde el servidor, ejecutar:

```bash
php bin/backup.php /ruta/privada/respaldos
```

El destino debe quedar fuera de `public`, fuera del directorio de datos y fuera del control de versiones. Resguardar juntos la base SQLite, los certificados, las claves privadas y la clave local de cifrado; sin esta última no se podrá recuperar una contraseña fiscal cifrada. Para una restauración directa, conservar la ruta absoluta del directorio privado, porque la base guarda las rutas de los archivos fiscales. Si se cambia esa ubicación, volver a cargar el par de certificado y clave desde Configuración. Mantener copias en otro equipo o destino privado y probar periódicamente la restauración en un entorno separado. El comando realiza un respaldo al ejecutarlo; programar su ejecución con el planificador del servidor si se necesitan copias periódicas.

No subir a Git certificados, claves, contraseñas, tickets WSAA, bases de datos ni respaldos. El código fuente se conserva en este repositorio; los datos operativos se guardan en el directorio privado.

## Verificación

```bash
find app public bin tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
for test_file in tests/*_test.php; do
  php "$test_file"
done
python3 tests/http_smoke.py
```

Las pruebas automatizadas usan datos temporales y transportes simulados. Cubren cálculos de IVA, catálogos, configuración, borradores masivos, conservación de datos históricos, emisión repetida, errores de conexión y conciliación. La prueba HTTP inicia un servidor PHP local temporal y comprueba acceso, formularios, API y protecciones de sesión sin credenciales fiscales. **No envían facturas reales a ARCA**. La integración debe completarse con una prueba de homologación usando las credenciales del emisor antes de habilitar la emisión productiva.

GitHub Actions ejecuta comprobación de sintaxis y pruebas en PHP 8.3 y 8.4 en cada push y pull request.
