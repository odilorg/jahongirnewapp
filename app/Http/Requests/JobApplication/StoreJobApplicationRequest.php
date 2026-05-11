<?php

declare(strict_types=1);

namespace App\Http\Requests\JobApplication;

use App\Enums\HR\ExperienceLevel;
use App\Enums\HR\LanguageLevel;
use App\Enums\HR\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a public job application submission at POST /jobs/apply.
 *
 * Server-side validation only — HTML5 `required` attributes on the
 * Blade form are cosmetic hints. Phase 1, 2026-05-11.
 *
 * # Phone normalisation
 *
 * The raw `phone` input is preserved here as-is; the controller does
 * the canonical normalisation (strip whitespace, ensure +998 prefix)
 * before persistence + dedup lookup. Validation only checks "looks
 * phone-ish" via regex.
 *
 * # Honeypot
 *
 * The `website` field is rendered as a hidden honeypot in the Blade
 * form. Real users never see / fill it. Bots fill every input. If
 * non-empty, the controller silently returns the success page
 * without writing anything to the DB.
 */
class StoreJobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public form, no auth
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            // Honeypot — accept ANY value through validation. The
            // controller's `!empty($request->input('website'))` check
            // intercepts non-empty values and returns silent success
            // without a DB write. Validating here as max:0 would
            // surface as a normal validation error and reveal the
            // honeypot's existence to spammers in the form-error
            // bag.
            'website' => ['nullable', 'string', 'max:255'],

            // Contact
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'min:9', 'max:32', 'regex:/^[\+\-\(\)\s0-9]+$/'],
            'whatsapp_phone' => ['nullable', 'string', 'max:32', 'regex:/^[\+\-\(\)\s0-9]+$/'],
            'age' => ['required', 'integer', 'min:14', 'max:80'],
            'city' => ['required', 'string', 'min:2', 'max:64'],

            // Position
            'position' => ['required', 'string', Rule::enum(Position::class)],
            'source' => ['nullable', 'string', 'max:32'],
            'source_reference' => ['nullable', 'string', 'max:255'],

            // Compensation & availability
            'expected_salary_uzs' => ['required', 'integer', 'min:500000', 'max:50000000'],
            'available_from' => ['nullable', 'date', 'after_or_equal:today'],
            'can_work_weekends' => ['required', 'boolean'],
            'can_work_nights' => ['required', 'boolean'],

            // Background
            'experience_level' => ['required', 'string', Rule::enum(ExperienceLevel::class)],
            'previous_workplace_text' => ['nullable', 'string', 'max:500'],
            'uzbek_level' => ['required', 'string', Rule::enum(LanguageLevel::class)],
            'russian_level' => ['required', 'string', Rule::enum(LanguageLevel::class)],
            'english_level' => ['required', 'string', Rule::enum(LanguageLevel::class)],

            // Position-specific answer — single value, validated against the
            // schema for the chosen position. Default = required string.
            'position_answer' => ['required', 'string', 'max:500'],

            // Optional CV/photo upload
            'cv' => [
                'nullable',
                'file',
                'max:5120', // 5 MB
                'mimes:pdf,doc,docx,jpg,jpeg,png',
            ],
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Пожалуйста, укажите ваше полное имя.',
            'phone.required' => 'Пожалуйста, укажите номер телефона.',
            'phone.regex' => 'Номер телефона может содержать только цифры, +, -, (, ) и пробелы.',
            'age.required' => 'Пожалуйста, укажите ваш возраст.',
            'age.min' => 'Возраст должен быть не менее 14 лет.',
            'age.max' => 'Пожалуйста, проверьте правильность возраста.',
            'city.required' => 'Пожалуйста, укажите город.',
            'position.required' => 'Пожалуйста, выберите должность.',
            'position.Illuminate\\Validation\\Rules\\Enum' => 'Пожалуйста, выберите должность из списка.',
            'expected_salary_uzs.required' => 'Пожалуйста, укажите ожидаемую зарплату как число в сумах. Детали можно обсудить на собеседовании.',
            'expected_salary_uzs.integer' => 'Пожалуйста, укажите ожидаемую зарплату как число в сумах. Детали можно обсудить на собеседовании.',
            'expected_salary_uzs.min' => 'Пожалуйста, укажите реалистичную сумму (минимум 500 000 сум).',
            'available_from.after_or_equal' => 'Дата начала работы не может быть в прошлом.',
            'can_work_weekends.required' => 'Пожалуйста, ответьте на вопрос о выходных.',
            'can_work_nights.required' => 'Пожалуйста, ответьте на вопрос о ночных сменах.',
            'experience_level.required' => 'Пожалуйста, выберите уровень опыта.',
            'uzbek_level.required' => 'Пожалуйста, укажите уровень узбекского.',
            'russian_level.required' => 'Пожалуйста, укажите уровень русского.',
            'english_level.required' => 'Пожалуйста, укажите уровень английского.',
            'position_answer.required' => 'Пожалуйста, ответьте на дополнительный вопрос для выбранной должности.',
            'cv.max' => 'Файл слишком большой (максимум 5 МБ).',
            'cv.mimes' => 'Допустимые форматы файла: PDF, DOC, DOCX, JPG, PNG.',
        ];
    }
}
