=== Fliinow - Financiación para WooCommerce ===
Contributors: fliinow
Tags: financing, payment, installments, travel, bnpl
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ofrece financiación a plazos en el checkout de WooCommerce con Fliinow.

== Description ==

Fliinow permite a tus clientes financiar sus compras a plazos directamente
desde el checkout de WooCommerce. Compatible con el checkout clásico y el
checkout basado en bloques (WooCommerce Blocks).

**Características:**

* Método de pago integrado en el checkout de WooCommerce
* Compatible con WooCommerce Blocks (checkout por bloques)
* Entorno sandbox para pruebas
* Configuración de importes mínimos y máximos
* Logs de depuración integrados
* Compatible con HPOS (High-Performance Order Storage)
* Extensible mediante filtros de WordPress

**Flujo del cliente:**

1. El cliente añade productos al carrito
2. En el checkout, selecciona "Financiar con Fliinow"
3. Completa el pedido y es redirigido a Fliinow
4. En Fliinow elige el plan de financiación y completa la solicitud
5. Si se aprueba, se confirma el pedido automáticamente
6. Si se rechaza/cancela, el pedido queda como fallido

== Installation ==

1. Sube la carpeta `fliinow-woocommerce` al directorio `/wp-content/plugins/`
2. Activa el plugin desde 'Plugins' en WordPress
3. Ve a WooCommerce → Ajustes → Pagos → Fliinow
4. Introduce tu API Key proporcionada por Fliinow
5. Activa el modo sandbox para probar antes de ir a producción

**Compilar el JavaScript (para WooCommerce Blocks):**

    cd fliinow-woocommerce
    npm install
    npm run build

== Frequently Asked Questions ==

= ¿Necesito una cuenta de Fliinow? =

Sí. Contacta con partners@fliinow.com para obtener tus credenciales API.

= ¿Funciona con el checkout por bloques? =

Sí. El plugin soporta tanto el checkout clásico como el basado en bloques.

= ¿Puedo personalizar los datos enviados a Fliinow? =

Sí. Usa el filtro `fliinow_wc_operation_data` para modificar el payload:

    add_filter( 'fliinow_wc_operation_data', function( $data, $order ) {
        $data['travelersNumber'] = 2;
        $data['packageName'] = 'Viaje personalizado';
        return $data;
    }, 10, 2 );

== Changelog ==

= 1.0.0 =
* Versión inicial
* Gateway de pago con soporte clásico y por bloques
* Configuración de sandbox/producción
* Callbacks de éxito/error
* Soporte HPOS
