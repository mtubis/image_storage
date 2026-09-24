<?php

declare(strict_types=1);

arch()->preset()->php();

// Besides naming rules, forbids dd/ddd/exit and env() outside config files.
arch()->preset()->laravel();

arch()->preset()->security();

arch('application code uses strict types')
    ->expect(['App', 'Database', 'Tests'])
    ->toUseStrictTypes();
