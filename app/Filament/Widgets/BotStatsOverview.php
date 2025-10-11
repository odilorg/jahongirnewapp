<?php

namespace App\Filament\Widgets;

use App\Models\TelegramBotConversation;
use App\Models\BotAnalytics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class BotStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $today = BotAnalytics::whereDate('date', Carbon::today())->first();
        $thisWeek = BotAnalytics::whereBetween('date', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->get();
        
        $todayMessages = $today?->total_messages ?? 0;
        $weekMessages = $thisWeek->sum('total_messages');
        
        $successRate = $today?->success_rate ?? 0;
        $avgResponseTime = $today?->average_response_time ?? 0;
        
        return [
            Stat::make('Messages Today', $todayMessages)
                ->description('Total bot interactions today')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('success'),
                
            Stat::make('This Week', $weekMessages)
                ->description('Messages this week')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),
                
            Stat::make('Success Rate', number_format($successRate, 1) . '%')
                ->description('Successfully processed today')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($successRate > 90 ? 'success' : ($successRate > 70 ? 'warning' : 'danger')),
                
            Stat::make('Avg Response Time', number_format($avgResponseTime, 2) . 's')
                ->description('Average processing time')
                ->descriptionIcon('heroicon-m-clock')
                ->color($avgResponseTime < 5 ? 'success' : ($avgResponseTime < 10 ? 'warning' : 'danger')),
        ];
    }
}
