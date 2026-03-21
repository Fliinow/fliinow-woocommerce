# Fliinow - Financiación para WooCommerce

[![PHP Tests](https://img.shields.io/badge/PHPUnit-106%20tests-brightgreen)](#testing)
[![JS Tests](https://img.shields.io/badge/Jest-17%20tests-brightgreen)](#testing)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0--9.6-purple)](https://woocommerce.com)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)](LICENSE)

Plugin de WooCommerce que integra Fliinow como método de pago de financiación a plazos.

## Estructura

```
fliinow-woocommerce/
├── fliinow-woocommerce.php           # Archivo principal del plugin
├── includes/
│   ├── class-fliinow-api.php         # Cliente PHP para la API de Fliinow
│   ├── class-fliinow-gateway.php     # WC_Payment_Gateway — método de pago
│   ├── class-fliinow-blocks.php      # Soporte para WooCommerce Blocks checkout
│   └── class-fliinow-webhook.php     # Handler de callbacks (retorno desde Fliinow)
├── src/
│   └── index.js                      # Registro JS del método de pago (Blocks)
├── build/                            # Archivos compilados (generados por npm run build)
├── assets/
│   └── fliinow-logo.svg             # Logo
├── package.json
├── webpack.config.js
└── readme.txt                        # Readme formato WordPress
```

## Instalación

### 1. Copiar el plugin

```bash
cp -r fliinow-woocommerce /ruta/a/wordpress/wp-content/plugins/
```

### 2. Compilar JavaScript (necesario para WooCommerce Blocks)

```bash
cd fliinow-woocommerce
npm install
npm run build
```

### 3. Activar en WordPress

Ve a **Plugins** → **Activar** "Fliinow - Financiación para WooCommerce"

### 4. Configurar

Ve a **WooCommerce → Ajustes → Pagos → Fliinow** y configura:

| Campo | Descripción |
|---|---|
| **API Key** | Tu clave `fk_test_*` (sandbox) o `fk_live_*` (producción) |
| **Modo Sandbox** | Activar para pruebas con `demo.fliinow.com` |
| **Importe mínimo** | Mínimo del carrito para mostrar financiación (default: 60€) |
| **Importe máximo** | Máximo del carrito (0 = sin límite) |
| **Viaje combinado** | Marcar como package travel (Directiva UE) |

## Flujo de pago

```
Cliente checkout → Selecciona "Financiar con Fliinow"
       ↓
Plugin crea operación via POST /operations
       ↓
Redirige a financingUrl (Fliinow)
       ↓
Cliente elige plan y completa solicitud
       ↓
Fliinow redirige a successCallbackUrl o errorCallbackUrl
       ↓
Plugin verifica estado y actualiza pedido:
  - FAVORABLE/CONFIRMED → pedido completado ✓
  - PENDING → pedido en espera
  - ERROR/REFUSED → pedido fallido
```

## Extensibilidad

### Filtro: `fliinow_wc_operation_data`

Permite modificar los datos enviados a la API de Fliinow antes de crear la operación:

```php
add_filter( 'fliinow_wc_operation_data', function( $data, $order ) {
    // Añadir datos de vuelo
    $data['flightDtoList'] = [
        [
            'origin'       => 'MAD',
            'destination'  => 'CDG',
            'flightType'   => 'ROUND_TRIP',
            'startDate'    => '15-06-2026',
            'endDate'      => '22-06-2026',
            'price'        => 450.00,
            'passengerList' => [
                [
                    'firstName' => $order->get_billing_first_name(),
                    'lastName'  => $order->get_billing_last_name(),
                    'birthDate' => '15-03-1985',
                ],
            ],
            'segments'     => [
                [
                    'flightNumber'       => 'IB1234',
                    'airlineCode'        => 'IB',
                    'originAirport'      => 'MAD',
                    'destinationAirport' => 'CDG',
                    'departureDateTime'  => '2026-06-15T08:00:00',
                    'arrivalDateTime'    => '2026-06-15T10:30:00',
                ],
            ],
        ],
    ];

    // Cambiar número de viajeros
    $data['travelersNumber'] = 2;

    return $data;
}, 10, 2 );
```

### Campos personalizados del cliente

El plugin busca estos meta fields en el pedido para rellenar datos obligatorios de Fliinow:

| Meta key | Descripción | Ejemplo |
|---|---|---|
| `_billing_document_id` | DNI/NIE/Pasaporte | `12345678A` |
| `_billing_document_validity` | Fecha caducidad documento | `31-12-2030` |
| `_billing_gender` | Género (`MALE` / `FEMALE`) | `MALE` |
| `_billing_birth_date` | Fecha nacimiento | `15-03-1985` |

Puedes añadir estos campos al checkout con plugins como "Checkout Field Editor" o programáticamente:

```php
add_filter( 'woocommerce_checkout_fields', function( $fields ) {
    $fields['billing']['billing_document_id'] = [
        'type'     => 'text',
        'label'    => 'DNI/NIE/Pasaporte',
        'required' => true,
        'priority' => 35,
    ];
    $fields['billing']['billing_birth_date'] = [
        'type'     => 'date',
        'label'    => 'Fecha de nacimiento',
        'required' => true,
        'priority' => 36,
    ];
    return $fields;
} );

// Guardar los campos custom en el order meta
add_action( 'woocommerce_checkout_update_order_meta', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! empty( $_POST['billing_document_id'] ) ) {
        $order->update_meta_data( '_billing_document_id', sanitize_text_field( $_POST['billing_document_id'] ) );
    }
    if ( ! empty( $_POST['billing_birth_date'] ) ) {
        $order->update_meta_data( '_billing_birth_date', sanitize_text_field( $_POST['billing_birth_date'] ) );
    }
    $order->save();
} );
```

## Compatibilidad

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- HPOS (High-Performance Order Storage) ✓
- WooCommerce Blocks checkout ✓
- Checkout clásico (shortcode) ✓

## Testing

### Requisitos para tests

```bash
# PHP tests
composer install

# JS tests
npm install
```

### Ejecutar tests

```bash
# Tests unitarios PHP (106 tests, 218 assertions)
vendor/bin/phpunit --testsuite=unit

# Tests de integración contra sandbox (requiere API key)
vendor/bin/phpunit --testsuite=integration

# Tests JS (17 tests)
npm run test:js

# Build producción
npm run build
```

La API key de sandbox se configura en `phpunit.xml` (variable `FLIINOW_TEST_API_KEY`).

## Distribución del Plugin

### Opción 1: Descarga directa (ZIP)

Los clientes pueden instalar el plugin descargando el ZIP desde GitHub Releases:

1. Ve a [Releases](../../releases) y descarga `fliinow-woocommerce-X.X.X.zip`
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin** → selecciona el ZIP
3. Activar y configurar en **WooCommerce → Ajustes → Pagos → Fliinow**

### Opción 2: Composer (para desarrolladores)

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Fliinow/fliinow-woocommerce"
    }
  ],
  "require": {
    "fliinow/fliinow-woocommerce": "^1.0"
  }
}
```

### Crear un release ZIP

```bash
# Desde la raíz del plugin
npm run build
git archive --format=zip --prefix=fliinow-woocommerce/ -o fliinow-woocommerce-1.0.0.zip HEAD -- \
  fliinow-woocommerce.php \
  includes/ \
  build/ \
  assets/ \
  languages/ \
  readme.txt \
  README.md

# O con gh CLI
gh release create v1.0.0 fliinow-woocommerce-1.0.0.zip --title "v1.0.0" --notes "Initial release"
```

## API Reference

Documentación completa de la API de Fliinow: https://api.docs.fliinow.com/

SDK TypeScript disponible: `npm install @fliinow-com/fliinow-partner-api`
