<?php

namespace Database\Seeders;

use App\Domain\Evaluation\Models\Checklist;
use Illuminate\Database\Seeder;

/**
 * DEMO-чек-листы по разд. 7.3 ТЗ. Критерии и веса тестовые — не корпоративный стандарт и не
 * основание для кадровых выводов. Редактирование — в конструкторе (этап D). Существующие не меняются.
 */
class DemoChecklistSeeder extends Seeder
{
    public const CHECKLISTS = [
        'demo_incoming_sales' => [
            'name' => 'Входящий — продажа (DEMO)',
            'criteria' => [
                ['greeting', 'Приветствие и представление', 'Сотрудник поздоровался, назвал компанию и своё имя.', 10, true, false],
                ['need', 'Выяснение запроса', 'Сотрудник выяснил, что интересует клиента.', 20, true, false],
                ['questions', 'Уточняющие вопросы', 'Сотрудник задал вопросы о бюджете, сроках, трейд-ин, комплектации.', 20, true, false],
                ['next_step', 'Предложение следующего шага', 'Сотрудник предложил конкретный шаг: визит, тест-драйв, расчёт.', 20, true, true],
                ['agreement', 'Подтверждение договорённостей', 'Сотрудник подтвердил дату, время и суть договорённости.', 20, true, false],
                ['closing', 'Завершение разговора', 'Вежливое завершение разговора.', 10, false, false],
            ],
        ],
        'demo_outgoing_contact' => [
            'name' => 'Исходящий — контакт (DEMO)',
            'criteria' => [
                ['identify', 'Идентификация клиента и представление', 'Сотрудник представился и убедился, что говорит с нужным человеком.', 20, true, false],
                ['purpose', 'Цель звонка', 'Сотрудник ясно обозначил цель звонка.', 30, true, false],
                ['next_action', 'Согласование дальнейшего действия', 'Согласовано конкретное следующее действие.', 30, true, true],
                ['closing', 'Завершение разговора', 'Вежливое завершение разговора.', 20, false, false],
            ],
        ],
        'demo_communication_standard' => [
            'name' => 'Стандарт коммуникации (DEMO)',
            'criteria' => [
                ['politeness', 'Вежливость', 'Сотрудник корректен и вежлив на протяжении разговора.', 40, true, false],
                ['clarity', 'Понятная речь', 'Речь сотрудника понятна, без жаргона.', 30, true, false],
                ['no_bad_phrases', 'Отсутствие некорректных формулировок', 'Нет грубых и недопустимых формулировок.', 30, true, true],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CHECKLISTS as $code => $def) {
            if (Checklist::query()->where('code', $code)->exists()) {
                continue;
            }
            $checklist = Checklist::create([
                'code' => $code,
                'name' => $def['name'],
                'description' => 'Демонстрационный чек-лист: критерии и веса тестовые, не для кадровых выводов.',
                'is_demo' => true,
            ]);
            $version = $checklist->versions()->create(['version' => 1, 'status' => 'published', 'published_at' => now()]);
            foreach ($def['criteria'] as $i => [$criterionCode, $title, $description, $weight, $required, $critical]) {
                $version->criteria()->create([
                    'code' => $criterionCode,
                    'position' => $i,
                    'title' => $title,
                    'description' => $description,
                    'ai_hint' => $description,
                    'weight' => $weight,
                    'is_required' => $required,
                    'is_critical' => $critical,
                ]);
            }
        }
    }
}
