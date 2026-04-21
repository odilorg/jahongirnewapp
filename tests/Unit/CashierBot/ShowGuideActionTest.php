<?php

declare(strict_types=1);

namespace Tests\Unit\CashierBot;

use App\Actions\CashierBot\Handlers\ShowGuideAction;
use PHPUnit\Framework\TestCase;

/**
 * Parity pin for ShowGuideAction — the Action extracted from
 * CashierBotController::showGuide + ::showGuideTopic.
 *
 * These tests assert byte-identical replies to what the controller
 * produced before extraction. The per-topic string bodies are not
 * re-asserted in full; we only pin the structural shape and a couple
 * of unique fragments per topic so the test stays readable while still
 * catching accidental edits to the guide copy.
 */
final class ShowGuideActionTest extends TestCase
{
    public function test_null_topic_returns_main_menu(): void
    {
        $reply = (new ShowGuideAction())->execute(null);

        $this->assertSame('inline', $reply['type']);
        $this->assertStringContainsString('📖', $reply['text']);
        $this->assertStringContainsString('Инструкция — Кассир Бот', $reply['text']);

        $rows = $reply['kb']['inline_keyboard'];
        $this->assertCount(5, $rows, 'main guide has five keyboard rows');
        $this->assertSame('guide_payment',  $rows[0][0]['callback_data']);
        $this->assertSame('guide_expense',  $rows[0][1]['callback_data']);
        $this->assertSame('guide_exchange', $rows[1][0]['callback_data']);
        $this->assertSame('guide_tips',     $rows[3][0]['callback_data']);
        $this->assertSame('menu',           $rows[4][0]['callback_data']);
    }

    public function test_empty_string_topic_is_treated_as_main_menu(): void
    {
        $menu  = (new ShowGuideAction())->execute(null);
        $empty = (new ShowGuideAction())->execute('');

        $this->assertSame($menu, $empty);
    }

    /**
     * @dataProvider topicFragments
     */
    public function test_each_known_topic_returns_its_body_and_back_button(string $topic, string $fragment): void
    {
        $reply = (new ShowGuideAction())->execute($topic);

        $this->assertSame('inline', $reply['type']);
        $this->assertStringContainsString($fragment, $reply['text']);
        $this->assertSame(
            [[['text' => '« К инструкции', 'callback_data' => 'guide']]],
            $reply['kb']['inline_keyboard'],
            'every topic body has the same single-button "back" keyboard'
        );
    }

    public static function topicFragments(): array
    {
        return [
            'payment'  => ['payment',  '💵 <b>Оплата гостя</b>'],
            'expense'  => ['expense',  '📤 <b>Расходы</b>'],
            'exchange' => ['exchange', '🔄 <b>Обмен валюты</b>'],
            'balance'  => ['balance',  '💰 <b>Баланс</b>'],
            'open'     => ['open',     '🟢 <b>Открытие смены</b>'],
            'close'    => ['close',    '🔒 <b>Закрытие смены</b>'],
            'tips'     => ['tips',     '💡 <b>Советы и правила</b>'],
        ];
    }

    public function test_unknown_topic_falls_back_to_not_found_message(): void
    {
        $reply = (new ShowGuideAction())->execute('not-a-real-topic');

        $this->assertSame('Раздел не найден.', $reply['text']);
        $this->assertSame(
            [[['text' => '« К инструкции', 'callback_data' => 'guide']]],
            $reply['kb']['inline_keyboard']
        );
    }
}
