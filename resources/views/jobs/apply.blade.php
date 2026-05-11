<x-jobs.layout title="Подача заявки на работу · Jahongir Hotel">
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800">
            <strong>Пожалуйста, исправьте ошибки:</strong>
            <ul class="mt-1 list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('jobs.apply.store') }}"
        enctype="multipart/form-data"
        x-data="{
            position: @js($preselected['position'] ?? ''),
            schemas: @js($positionSchemas),
            currentSchema() {
                return this.schemas[this.position] ?? null;
            }
        }"
        class="space-y-5"
    >
        @csrf

        {{-- Honeypot. Real users won't see this; bots fill all inputs. --}}
        <div aria-hidden="true" style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;">
            <label>Website (do not fill)
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </label>
        </div>

        {{-- Source — silent passthrough from URL --}}
        <input type="hidden" name="source" value="{{ $preselected['source'] ?? '' }}">

        {{-- 1. Full name --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Полное имя <span class="text-red-500">*</span></label>
            <input
                type="text"
                name="full_name"
                required
                maxlength="255"
                value="{{ old('full_name') }}"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
        </div>

        {{-- 2. Phone --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Номер телефона <span class="text-red-500">*</span></label>
            <input
                type="tel"
                name="phone"
                required
                maxlength="32"
                placeholder="+998 90 123 45 67"
                value="{{ old('phone') }}"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
        </div>

        {{-- WhatsApp (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">WhatsApp <span class="text-xs text-gray-400">(если отличается от номера телефона)</span></label>
            <input
                type="tel"
                name="whatsapp_phone"
                maxlength="32"
                value="{{ old('whatsapp_phone') }}"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
        </div>

        {{-- 3. Age --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Возраст <span class="text-red-500">*</span></label>
            <input
                type="number"
                name="age"
                required
                min="14"
                max="80"
                value="{{ old('age') }}"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
        </div>

        {{-- 4. City --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Город / район <span class="text-red-500">*</span></label>
            <input
                type="text"
                name="city"
                required
                maxlength="64"
                value="{{ old('city', 'Самарканд') }}"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
        </div>

        {{-- 5. Position --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Должность <span class="text-red-500">*</span></label>
            <select
                name="position"
                required
                x-model="position"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
                <option value="">— Выберите должность —</option>
                @foreach ($positions as $value => $label)
                    <option value="{{ $value }}" @selected(old('position', $preselected['position']) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- 6. Expected salary --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ожидаемая зарплата (в сумах) <span class="text-red-500">*</span></label>
            <input
                type="number"
                name="expected_salary_uzs"
                required
                min="500000"
                step="50000"
                placeholder="3 000 000"
                value="{{ old('expected_salary_uzs') }}"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
            <p class="text-xs text-gray-500 mt-1">Укажите сумму в сумах. Детали можно обсудить на собеседовании.</p>
        </div>

        {{-- 7. Available from --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Когда можете начать работать?</label>
            <input
                type="date"
                name="available_from"
                value="{{ old('available_from') }}"
                min="{{ now()->toDateString() }}"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >
        </div>

        {{-- 8 + 9: weekends + nights --}}
        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Работа в выходные? <span class="text-red-500">*</span></label>
                <select name="can_work_weekends" required class="w-full rounded-lg border-gray-300">
                    <option value="">—</option>
                    <option value="1" @selected(old('can_work_weekends') === '1')>Да</option>
                    <option value="0" @selected(old('can_work_weekends') === '0')>Нет</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ночные смены? <span class="text-red-500">*</span></label>
                <select name="can_work_nights" required class="w-full rounded-lg border-gray-300">
                    <option value="">—</option>
                    <option value="1" @selected(old('can_work_nights') === '1')>Да</option>
                    <option value="0" @selected(old('can_work_nights') === '0')>Нет</option>
                </select>
            </div>
        </fieldset>

        {{-- 10. Experience level --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Опыт работы по этой профессии <span class="text-red-500">*</span></label>
            <select name="experience_level" required class="w-full rounded-lg border-gray-300">
                <option value="">— Выберите —</option>
                @foreach ($experienceLevels as $value => $label)
                    <option value="{{ $value }}" @selected(old('experience_level') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- 11. Previous workplace (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Где работали раньше? <span class="text-xs text-gray-400">(не обязательно)</span></label>
            <textarea
                name="previous_workplace_text"
                rows="2"
                maxlength="500"
                placeholder="Краткое описание последнего места работы"
                class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            >{{ old('previous_workplace_text') }}</textarea>
        </div>

        {{-- 12-14: language levels --}}
        <fieldset class="space-y-3">
            <legend class="text-sm font-medium text-gray-700">Языки</legend>
            @foreach (['uzbek' => 'Узбекский', 'russian' => 'Русский', 'english' => 'Английский'] as $key => $langLabel)
                <div class="flex items-center gap-3">
                    <span class="w-28 text-sm text-gray-600">{{ $langLabel }} <span class="text-red-500">*</span></span>
                    <select name="{{ $key }}_level" required class="flex-1 rounded-lg border-gray-300 text-sm">
                        <option value="">—</option>
                        @foreach ($languageLevels as $value => $label)
                            <option value="{{ $value }}" @selected(old("{$key}_level") === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </fieldset>

        {{-- 15. Position-specific question — Alpine conditionally renders the right widget --}}
        <div x-show="position && currentSchema()" x-cloak>
            <label class="block text-sm font-medium text-gray-700 mb-1" x-text="currentSchema()?.question"></label>

            {{-- yes/no widget --}}
            <template x-if="currentSchema()?.schema?.type === 'yes_no'">
                <select name="position_answer" class="w-full rounded-lg border-gray-300" x-bind:required="!!position">
                    <option value="">—</option>
                    <option value="yes">Да</option>
                    <option value="no">Нет</option>
                </select>
            </template>

            {{-- select-options widget (kitchen role) --}}
            <template x-if="currentSchema()?.schema?.type === 'select'">
                <select name="position_answer" class="w-full rounded-lg border-gray-300" x-bind:required="!!position">
                    <option value="">—</option>
                    <template x-for="(label, value) in currentSchema().schema.options" :key="value">
                        <option :value="value" x-text="label"></option>
                    </template>
                </select>
            </template>

            {{-- free-text widget (Other) --}}
            <template x-if="currentSchema()?.schema?.type === 'text'">
                <input
                    type="text"
                    name="position_answer"
                    maxlength="500"
                    class="w-full rounded-lg border-gray-300"
                    x-bind:required="!!position"
                >
            </template>
        </div>

        {{-- 16. CV/photo (optional) --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Резюме или фото <span class="text-xs text-gray-400">(не обязательно)</span></label>
            <input
                type="file"
                name="cv"
                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                class="w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-sm file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
            >
            <p class="text-xs text-gray-500 mt-1">PDF, DOC, JPG, PNG · до 5 МБ</p>
        </div>

        {{-- Submit --}}
        <div class="pt-2">
            <button
                type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 rounded-lg transition"
            >
                Отправить заявку
            </button>
        </div>
    </form>
</x-jobs.layout>
