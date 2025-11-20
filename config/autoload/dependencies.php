<?php

declare(strict_types=1);

return [
    \App\Repository\AccountRepository::class => \App\Repository\AccountRepository::class,
    \App\Repository\AccountWithdrawRepository::class => \App\Repository\AccountWithdrawRepository::class,
    \App\Service\WithdrawService::class => \App\Service\WithdrawService::class,
    \App\Service\EmailService::class => \App\Service\EmailService::class,
];

