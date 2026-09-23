<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Data\AccountData;

final class Accounts
{
    /** @return list<AccountData> */
    public static function data(): array
    {
        return [
            new AccountData('42', '7', '682', 'asset'),
            new AccountData('43', '8', '683', 'asset'),
            new AccountData('44', '7', '684', 'asset', active: false),
            new AccountData('7001', '7', null, 'revenue'),
            new AccountData('7002', '7', null, 'expense'),
            new AccountData('8001', '8', null, 'revenue'),
            new AccountData('8002', '8', null, 'expense'),
        ];
    }

    public static function financial(): Account
    {
        return Account::fromData(self::data()[0]);
    }

    public static function revenue(): Account
    {
        return Account::fromData(self::data()[3]);
    }

    public static function expense(): Account
    {
        return Account::fromData(self::data()[4]);
    }
}
