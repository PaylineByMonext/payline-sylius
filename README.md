# Overwiev
---
This plugin allows you to use Monext Payline payment system with Sylius ecommerce app.

## Installation
----

$ composer require monext/payline-sylius

Add plugin dependencies to your config/bundles.php file:

Add this line to the end of the array ( if it does not already exist ) 
```php
    MonextSyliusPlugin\MonextSyliusPlugin::class => ['all' => true]
```

Clear cache:
$ composer require monext/payline-sylius
