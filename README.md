## Installation

1. Copy files to `core/components/simplecart` directory.
2. Create new payment method with the name `paypalrest`.
3. From the context menu of the new payment method select `Intall method` to create all required method settings.
4. [Create PayPal REST application](https://developer.paypal.com/webapps/developer/applications/myapps) (or use existing one) to obtain the `Client ID` and `Secret`.
5. Open payment method configuration by selecting `Configuration` from the context menu and fill settings values as described in [Configuration][].

## Configuration

 - `clientId` - Client ID from PayPal application credentials 
 - `clientSecret` - Secret from PayPal application credentials
 - `shipping` - whether or not shipping address provided by the client should be used. Possible values - `0` and `1`. If option is active, the delivery address should match the [validation rules](https://developer.paypal.com/docs/api/#shippingaddress-object), especially country should be provided as 2-letter code
 - `sandbox` - enables or disables the sandbox mode, possible values are `0` and `1`
 - `sandbox.currency` - the currency that should be used in sandbox mode
 - `debug` - enables detailed logging when creating and exeucting the payment
 
## PayPal SDK update
 
[Download latest archive](https://github.com/paypal/PayPal-PHP-SDK/releases) and extract its contents to `core/components/simplecart/gateways/paypalrest/lib` folder.
