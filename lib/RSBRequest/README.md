RSBRequests
=======

This repository holds all interfaces/classes/traits related to
[PSR-3](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md).

Note that this is not a logger of its own. It is merely an interface that
describes a logger. See the specification for more details.

Description
========

This library provides secure queries against the Russian Standart Bank. Developed in IPOL.

Usage
-----

If you need a Requests, you can use the interface like this:

```php
<?php

use Ipol\RSBRequest\MainController;
use Ipol\RSBRequest\Response\RenewTransResponse;

class Foo
{
    private $controller;
    
    /**
    * @param $url                 - REQUIRED!
    * @param $path_to_file_key    - REQUIRED!
    * @param $path_to_file_pem    - REQUIRED!
    * @param $path_to_file_ca     - REQUIRED!
    * @param $logfile
    * @return void
    * @throws Exception
     */
    public function createController($url,$path_to_file_key,$path_to_file_pem,$path_to_file_ca,$logfile='')
    {
        $this->controller = new MainController($url,$path_to_file_key,$path_to_file_pem,$path_to_file_ca,$logfile);
        
        // do something useful
    }
    
    /**
    * @param array $request
    * @return RenewTransResponse
     */
    public function createRequest(array $request):RenewTransResponse
    {
        return $this->controller->updateTransaction($request);
    }
    
}
```

Additional information can be obtained by [contacting us](https://ipol.ru/).
