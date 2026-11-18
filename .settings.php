<?php

return [
    'console' => [
        'value' => [
            'commands' => [
                \Aclips\Fakesign\Cli\Command\StatusCommand::class,
                \Aclips\Fakesign\Cli\Command\JournalCommand::class,
                \Aclips\Fakesign\Cli\Command\CheckIsolationCommand::class,
            ],
        ],
        'readonly' => true,
    ],
];
