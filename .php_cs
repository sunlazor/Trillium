<?php

$finder = \Symfony\CS\Finder\DefaultFinder::create()
    ->files()
    ->name('*.php')
    ->exclude('vendor')
    ->exclude('application/resources')
    ->in(__DIR__)
;

return \Symfony\CS\Config\Config::create()->finder($finder);