<?php

require __DIR__.'/vendor/autoload.php';

$ref = new ReflectionClass('App\Services\LogisticsIncidentAuthorizationService');
echo 'is instantiable: '.var_export($ref->isInstantiable(), true)."\n";
echo 'num methods: '.count($ref->getMethods())."\n";
foreach ($ref->getMethods() as $m) {
    echo '  - '.$m->getName()."\n";
}
