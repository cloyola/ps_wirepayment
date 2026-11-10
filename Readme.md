# Wire payment

Módulo de pago por transferencia bancaria para PrestaShop, basado en [ps_wirepayment](https://github.com/PrestaShop/ps_wirepayment).

La versión mantenida de este proyecto está en [https://github.com/cloyola/ps_wirepayment](https://github.com/cloyola/ps_wirepayment).

## Versión 2.1.0

Esta versión adapta el módulo a PrestaShop 9.2 y PHP 8.4, y conserva los datos bancarios propios de la tienda.

- Cuenta bancaria y cuenta externa en la configuración, el checkout, la página de confirmación y los correos del pedido.
- El pedido ya no se interrumpe si `classes/Mail.php` no define `Mail::TYPE_BOTH_AUTOMATIC_TEXT`. PrestaShop 9.2 consulta esa constante al confirmar el pago.
- Importes con `formatPrice()`, en lugar de `Tools::displayPrice()`, eliminado en PrestaShop 9.
- Confirmación del pedido en el hook `displayPaymentReturn`.
- El logo de la página de configuración usa la ruta del módulo. La ruta relativa anterior se resolvía dentro del back office de Symfony y devolvía un 404.

Compatible con PrestaShop 8.1 o superior. Probado con PrestaShop 9.2 y PHP 8.4.
