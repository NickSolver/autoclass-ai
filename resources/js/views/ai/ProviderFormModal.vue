<script setup lang="ts">
import { aiApi } from '@/api';
import type { ProviderConfig, ProviderKindCode, ProvidersOverview } from '@/api/types';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useForm } from '@/composables/useForm';
import { computed, reactive, watch } from 'vue';

const props = defineProps<{ overview: ProvidersOverview; provider?: ProviderConfig | null; kind: ProviderKindCode }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();
const { submitting, errors, formError, submit, reset } = useForm();

const form = reactive({
    driver: 'mock',
    preset: '',
    name: '',
    endpoint: '',
    model: '',
    timeout_seconds: 120 as number | string,
    price_per_minute: '' as number | string,
    price_per_1k_tokens_in: '' as number | string,
    price_per_1k_tokens_out: '' as number | string,
    mock_scenario: 'normal',
    json_mode: true,
    temperature: 0 as number | string,
    max_tokens: '' as number | string,
    language: '',
    multichannel: true,
    speaker_labeling: true,
    folder_id: '',
});

const editing = computed(() => !!props.provider);
const drivers = computed(() => props.overview.drivers[props.kind] ?? []);
const driver = computed(() => drivers.value.find((d) => d.code === form.driver));
const external = computed(() => driver.value?.external ?? false);
const presets = computed(() => props.overview.presets[form.driver] ?? []);
const isAsr = computed(() => props.kind === 'asr');
const driverOptions = computed(() =>
    drivers.value.map((d) => ({ value: d.code, label: d.available ? d.name : `${d.name} — ожидает документации`, disabled: !d.available })),
);
const unavailable = computed(() => drivers.value.filter((d) => !d.available));

watch(open, (value) => {
    if (!value) return;
    reset();
    const p = props.provider;
    const s = p?.settings ?? {};
    Object.assign(form, {
        driver: p?.driver ?? drivers.value.find((d) => d.available)?.code ?? 'mock',
        preset: '',
        name: p?.name ?? '',
        endpoint: p?.endpoint ?? '',
        model: p?.model ?? '',
        timeout_seconds: s.timeout_seconds ?? 120,
        price_per_minute: s.price_per_minute ?? '',
        price_per_1k_tokens_in: s.price_per_1k_tokens_in ?? '',
        price_per_1k_tokens_out: s.price_per_1k_tokens_out ?? '',
        mock_scenario: (s.mock_scenario as string) ?? 'normal',
        json_mode: (s.json_mode as boolean) ?? true,
        temperature: s.temperature ?? 0,
        max_tokens: s.max_tokens ?? '',
        language: (s.language as string) ?? '',
        multichannel: (s.multichannel as boolean) ?? true,
        speaker_labeling: (s.speaker_labeling as boolean) ?? true,
        folder_id: (s.folder_id as string) ?? '',
    });
});

// Новый провайдер: адрес и модель по умолчанию для выбранного сервиса.
watch(() => form.driver, () => {
    if (editing.value) return;
    form.endpoint = driver.value?.endpoint ?? '';
    form.model = driver.value?.models?.[0] ?? '';
    form.preset = '';
    if (!form.name || drivers.value.some((d) => d.name === form.name)) form.name = driver.value?.name ?? '';
});

watch(() => form.preset, (name) => {
    const preset = presets.value.find((p) => p.name === name);
    if (!preset) return;
    form.endpoint = preset.endpoint;
    form.model = preset.model;
    form.name = preset.name;
});

function numberOrNull(v: number | string): number | null {
    return v === '' || v === null ? null : Number(v);
}

async function save(): Promise<void> {
    const settings: Record<string, unknown> = { timeout_seconds: numberOrNull(form.timeout_seconds) ?? 120 };
    if (isAsr.value) settings.price_per_minute = numberOrNull(form.price_per_minute);
    else {
        settings.price_per_1k_tokens_in = numberOrNull(form.price_per_1k_tokens_in);
        settings.price_per_1k_tokens_out = numberOrNull(form.price_per_1k_tokens_out);
    }
    if (form.driver === 'mock') settings.mock_scenario = form.mock_scenario;
    if (form.driver === 'openai_compatible') {
        Object.assign(settings, { json_mode: form.json_mode, temperature: numberOrNull(form.temperature) ?? 0, max_tokens: numberOrNull(form.max_tokens) });
    }
    if (isAsr.value && external.value && form.language) settings.language = form.language;
    if (['yandex', 'tbank'].includes(form.driver)) settings.multichannel = form.multichannel;
    if (form.driver === 'yandex') Object.assign(settings, { speaker_labeling: form.speaker_labeling, folder_id: form.folder_id || null });

    const body = { name: form.name, endpoint: external.value ? form.endpoint : null, model: external.value ? form.model || null : null, settings };
    const result = await submit(() =>
        props.provider
            ? aiApi.update(props.provider.id, { ...body, lock_version: props.provider.lock_version })
            : aiApi.create({ ...body, kind: props.kind, driver: form.driver }),
    );
    if (result) {
        open.value = false;
        emit('saved');
    }
}
</script>

<template>
    <UiModal v-model:open="open" :title="editing ? 'Настройки провайдера' : 'Новый провайдер'" size="lg">
        <form id="provider-form" class="space-y-4" @submit.prevent="save">
            <UiAlert v-if="formError || errors.lock_version" tone="danger">{{ errors.lock_version || formError }}</UiAlert>

            <div class="grid gap-4 sm:grid-cols-2">
                <UiSelect v-model="form.driver" label="Сервис" :disabled="editing" :options="driverOptions" :error="errors.driver" />
                <UiSelect v-if="!editing && presets.length" v-model="form.preset" label="Готовые настройки" placeholder="Вручную" :options="presets.map((p) => ({ value: p.name, label: p.name }))" />
            </div>
            <p v-if="driver?.note" class="text-[12px] text-muted">{{ driver.note }}</p>
            <details v-if="!editing && unavailable.length" class="text-[12px] text-muted">
                <summary class="cursor-pointer">Почему некоторые сервисы недоступны</summary>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    <li v-for="d in unavailable" :key="d.code"><strong>{{ d.name }}:</strong> {{ d.reason }}</li>
                </ul>
            </details>

            <UiAlert v-if="external" tone="warning">
                Внешний сервис: {{ isAsr ? 'записи звонков' : 'расшифровки звонков' }} будут передаваться за пределы портала.
                Включить его можно только после разрешения внешней обработки (юридическая проверка). Ключ задаётся отдельно после сохранения.
            </UiAlert>
            <UiAlert v-else-if="form.driver === 'mock'" tone="info">
                Тестовый провайдер работает внутри портала и ничего не отправляет наружу.
                {{ isAsr ? 'Расшифровка — синтетический диалог, а не распознавание записи.' : 'Оценка — сопоставление фраз демо-чек-листов.' }}
            </UiAlert>

            <UiInput v-model="form.name" label="Название" required :error="errors.name" />

            <div v-if="external" class="grid gap-4 sm:grid-cols-2">
                <UiInput v-model="form.endpoint" label="Адрес API" required :error="errors.endpoint" hint="Только HTTPS." />
                <UiSelect
                    v-if="driver?.models?.length"
                    v-model="form.model"
                    label="Модель"
                    :options="driver.models.map((m) => ({ value: m, label: m }))"
                    :required="driver.model_required"
                    :error="errors.model"
                />
                <UiInput v-else v-model="form.model" label="Модель" :required="driver?.model_required" :placeholder="driver?.model_required ? 'например, deepseek-flash' : 'по умолчанию сервиса'" :error="errors.model" />
            </div>

            <div v-if="isAsr && external" class="grid gap-4 sm:grid-cols-3">
                <UiInput v-model="form.language" label="Язык" :placeholder="form.driver === 'openai' ? 'ru' : 'ru-RU'" />
                <UiCheckbox v-if="['yandex', 'tbank'].includes(form.driver)" v-model="form.multichannel" label="Стерео: каналы — говорящие" hint="Если запись стерео, сотрудник и клиент различаются по каналам." class="pt-6" />
                <UiCheckbox v-if="form.driver === 'yandex'" v-model="form.speaker_labeling" label="Разметка говорящих для моно" class="pt-6" />
            </div>
            <UiInput v-if="form.driver === 'yandex'" v-model="form.folder_id" label="ID каталога Yandex Cloud" hint="Нужен, если ключ не привязан к каталогу; иначе можно оставить пустым." />

            <UiSelect
                v-if="form.driver === 'mock'"
                v-model="form.mock_scenario"
                label="Режим тестового провайдера"
                :options="overview.mock_scenarios[kind].map((s) => ({ value: s.code, label: s.name }))"
                hint="Режимы сбоев — для проверки повторов, резервного провайдера и ручной проверки."
                :error="errors['settings.mock_scenario']"
            />

            <div v-if="form.driver === 'openai_compatible'" class="grid gap-4 sm:grid-cols-3">
                <UiInput v-model="form.temperature" type="number" label="Температура" hint="0 — самые устойчивые ответы." />
                <UiInput v-model="form.max_tokens" type="number" label="Лимит ответа, токенов" hint="Пусто — по умолчанию сервиса." />
                <UiCheckbox v-model="form.json_mode" label="JSON-режим" hint="response_format: json_object." class="pt-6" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <UiInput v-model="form.timeout_seconds" type="number" label="Тайм-аут запроса, с" />
                <template v-if="isAsr">
                    <UiInput v-model="form.price_per_minute" type="number" label="Цена за минуту, ₽" hint="Для учёта стоимости." />
                </template>
                <template v-else>
                    <UiInput v-model="form.price_per_1k_tokens_in" type="number" label="Цена 1 тыс. токенов запроса, ₽" />
                    <UiInput v-model="form.price_per_1k_tokens_out" type="number" label="Цена 1 тыс. токенов ответа, ₽" />
                </template>
            </div>
        </form>
        <template #footer>
            <UiButton @click="open = false">Отмена</UiButton>
            <UiButton type="submit" form="provider-form" variant="primary" :loading="submitting">Сохранить</UiButton>
        </template>
    </UiModal>
</template>
