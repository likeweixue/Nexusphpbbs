<?php
namespace App\Filament\Pages;

use Filament\Support\Enums\Width;
use App\Filament\Widgets\AccountInfo;
use App\Filament\Widgets\LatestUsers;
use App\Filament\Widgets\SystemInfo;
use App\Filament\Widgets\UserClassStat;
use App\Filament\Widgets\UserStat;
use App\Filament\Widgets\UserTrend;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected Width|string|null $maxContentWidth = 'full';

    public function getWidgets(): array
    {
        return [
            AccountInfo::class,
            LatestUsers::class,
            UserTrend::class,
            UserStat::class,
            UserClassStat::class,
            SystemInfo::class,
        ];
    }
}
