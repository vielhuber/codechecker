<?php
declare(strict_types=1);

return new PhpCsFixer\Config()
    ->setRules(['@PSR12' => true])
    ->setRiskyAllowed(false)
    ->setUsingCache(false);
