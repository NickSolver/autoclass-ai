<script setup lang="ts">
import { rolesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { PermissionRegistry, Role, ScopeTypeCode } from '@/api/types';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { permissionCategories, scopeTypeLabels } from '@/utils/format';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

const props = defineProps<{ id: number | null }>();
const router = useRouter();
const { push } = useToasts();
const role = ref<Role | null>(null);
const registry = ref<PermissionRegistry | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const form = reactive({
    code: '',
    name: '',
    description: '',
    allowed_scope_types: [] as ScopeTypeCode[],
    permissions: new Set<string>(),
});
const { submitting, errors, formError, submit } = useForm();

const isNew = computed(() => props.id === null);
const editable = computed(() => isNew.value || !!role.value?.can.update);

const grouped = computed(() => {
    const groups = new Map<string, PermissionRegistry['data']>();
    for (const p of registry.value?.data ?? []) groups.set(p.category, [...(groups.get(p.category) ?? []), p]);
    return [...groups.entries()];
});

function fill(r: Role): void {
    role.value = r;
    Object.assign(form, {
        code: r.code,
        name: r.name,
        description: r.description ?? '',
        allowed_scope_types: [...r.allowed_scope_types],
        permissions: new Set(r.permissions ?? []),
    });
}

onMounted(async () => {
    try {
        const [reg, r] = await Promise.all([rolesApi.permissions(), props.id ? rolesApi.show(props.id) : Promise.resolve(null)]);
        registry.value = reg;
        if (r) fill(r);
    } catch (e) {
        loadError.value = e instanceof ApiError ? e.message : 'Не удалось загрузить роль.';
    } finally {
        loading.value = false;
    }
});

function togglePermission(code: string, on: boolean): void {
    const next = new Set(form.permissions);
    if (on) next.add(code);
    else next.delete(code);
    form.permissions = next;
}

function toggleCategory(codes: string[], on: boolean): void {
    const next = new Set(form.permissions);
    codes.forEach((c) => (on ? next.add(c) : next.delete(c)));
    form.permissions = next;
}

function toggleScope(code: ScopeTypeCode, on: boolean): void {
    form.allowed_scope_types = on ? [...form.allowed_scope_types, code] : form.allowed_scope_types.filter((t) => t !== code);
}

async function save(): Promise<void> {
    const body = {
        name: form.name,
        description: form.description || null,
        allowed_scope_types: form.allowed_scope_types,
        permissions: [...form.permissions],
    };
    const result = await submit(() =>
        isNew.value ? rolesApi.create({ ...body, code: form.code }) : rolesApi.update(props.id!, { ...body, lock_version: role.value!.lock_version }),
    );
    if (result) {
        push('Роль сохранена.');
        if (isNew.value) await router.replace(`/roles/${result.id}`);
        fill(result);
    }
}

const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteError = ref<string>();
async function confirmDelete(): Promise<void> {
    deleteBusy.value = true;
    deleteError.value = undefined;
    try {
        await rolesApi.remove(props.id!);
        push('Роль удалена.');
        await router.replace('/roles');
    } catch (e) {
        deleteError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Ошибка.';
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="loadError" tone="danger" title="Роль недоступна">{{ loadError }}</UiAlert>
    <form v-else @submit.prevent="save">
        <PageHeader :title="isNew ? 'Новая роль' : form.name" :description="isNew ? undefined : `Код: ${form.code}`">
            <template #actions>
                <UiBadge v-if="role?.is_system">шаблон</UiBadge>
                <UiButton v-if="role?.can.delete" variant="ghost" @click="deleteError = undefined; deleteOpen = true">Удалить</UiButton>
                <UiButton v-if="editable" type="submit" variant="primary" :loading="submitting">Сохранить</UiButton>
            </template>
        </PageHeader>

        <UiAlert v-if="!editable" tone="info" class="mb-5">Просмотр. Изменять роли может только суперадминистратор (право «Управление ролями» на уровне группы).</UiAlert>
        <UiAlert v-if="formError || errors.lock_version || errors.role" tone="danger" class="mb-5">{{ errors.lock_version || errors.role || formError }}</UiAlert>
        <UiAlert v-if="role && (role.assignments_count ?? 0) > 0 && editable" tone="warning" class="mb-5">
            Роль назначена {{ role.assignments_count }} раз(а). Изменение прав сразу повлияет на всех этих пользователей.
        </UiAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <UiCard title="Описание">
                <fieldset :disabled="!editable" class="space-y-4">
                    <UiInput v-if="isNew" v-model="form.code" label="Код" required :error="errors.code" hint="Латиница в нижнем регистре, цифры, подчёркивание. Не меняется." />
                    <UiInput v-model="form.name" label="Название" required :error="errors.name" />
                    <UiTextarea v-model="form.description" label="Описание" :error="errors.description" />
                    <div>
                        <p class="mb-2 text-[13px] font-medium">Можно назначать на уровень</p>
                        <div class="space-y-1.5">
                            <UiCheckbox
                                v-for="t in registry?.scope_types ?? []"
                                :key="t.code"
                                :model-value="form.allowed_scope_types.includes(t.code)"
                                :label="scopeTypeLabels[t.code]"
                                :disabled="!editable"
                                @update:model-value="(v) => toggleScope(t.code, !!v)"
                            />
                        </div>
                        <p v-if="errors.allowed_scope_types" class="mt-1 text-[12px] text-red-600">{{ errors.allowed_scope_types }}</p>
                    </div>
                </fieldset>
            </UiCard>

            <UiCard title="Права" :description="`Выбрано: ${form.permissions.size}`" class="lg:col-span-2">
                <div class="space-y-5">
                    <fieldset v-for="[category, perms] in grouped" :key="category" :disabled="!editable">
                        <legend class="mb-2 flex w-full items-center gap-2">
                            <span class="font-medium">{{ permissionCategories[category] ?? category }}</span>
                            <UiBadge v-if="!perms[0]?.module_available" tone="warning">модуль в разработке</UiBadge>
                            <button
                                v-if="editable"
                                type="button"
                                class="ml-auto text-[12px] text-brand-700 hover:underline"
                                @click="toggleCategory(perms.map((p) => p.code), !perms.every((p) => form.permissions.has(p.code)))"
                            >
                                {{ perms.every((p) => form.permissions.has(p.code)) ? 'снять все' : 'выбрать все' }}
                            </button>
                        </legend>
                        <div class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                            <UiCheckbox
                                v-for="p in perms"
                                :key="p.code"
                                :model-value="form.permissions.has(p.code)"
                                :label="p.name"
                                :hint="p.code"
                                :disabled="!editable"
                                @update:model-value="(v) => togglePermission(p.code, !!v)"
                            />
                        </div>
                    </fieldset>
                </div>
            </UiCard>
        </div>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Удалить роль?"
            message="Удалить можно только роль, которая никому не назначена."
            confirm-label="Удалить"
            danger
            :loading="deleteBusy"
            :error="deleteError"
            @confirm="confirmDelete"
        />
    </form>
</template>
