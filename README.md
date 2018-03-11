# aerys

[![Build Status](https://travis-ci.org/amphp/aerys.svg?branch=master)](https://travis-ci.org/amphp/aerys)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/amphp/aerys/blob/master/LICENSE)

Aerys is a non-blocking HTTP/1.1 and HTTP/2 application, WebSocket and static file server written in PHP based on [Amp](https://github.com/amphp/amp).

## Deprecation

This repository is deprecated in favor of [`amphp/http-server`](https://github.com/amphp/http-server).
It still exists to keep the documentation and also Packagist working as before.

## Installation

```bash
composer require amphp/aerys
```

## Documentation

- [Official Documentation](http://amphp.org/aerys/)
- [Getting Started with Aerys](http://blog.kelunik.com/2015/10/21/getting-started-with-aerys.html)
- [Getting Started with Aerys WebSockets](http://blog.kelunik.com/2015/10/20/getting-started-with-aerys-websockets.html)

## Running a Server

```bash
php bin/aerys -c demo.php
```

Simply execute the `aerys` binary (with PHP 7) to start a server listening on `http://localhost/` using
the default configuration file (packaged with the repository).

Add a `-d` switch to see some debug output like the routes called etc.:

```bash
php bin/aerys -d -c demo.php
```

## Config File

Use the `-c, --config` switches to define the config file:

```bash
php bin/aerys -c /path/to/my/config.php
```

Use the `-h, --help` switches for more instructions.

## Static File Serving

To start a static file server simply pass a root handler as part of your config file.

```php
return (new Aerys\Host)
    ->expose("*", 1337)
    ->use(Aerys\root(__DIR__ . "/public"));
```

## Security

If you discover any security related issues, please email `bobwei9@hotmail.com` or `me@kelunik.com` instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
