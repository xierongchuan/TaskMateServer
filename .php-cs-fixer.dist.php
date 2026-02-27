<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/app')
    ->in(__DIR__ . '/routes')
    ->name('*.php');

return (new Config())
    ->setRules([
        '@PSR12'                 => true,
        'declare_strict_types'   => true,
        'strict_param'           => true,
        // убирает лишние пустые строки
        'no_extra_blank_lines'   => [
            'tokens' => [
                'curly_brace_block',
                'extra',
                'parenthesis_brace_block',
                'square_brace_block',
                'throw',
                'use',
            ],
        ],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setUsingCache(true);
