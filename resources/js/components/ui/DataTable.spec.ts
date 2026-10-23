import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DataTable from './DataTable.vue';

const columns = [{ key: 'name', label: 'Название' }];
const stubs = { RouterLink: { template: '<a><slot /></a>' } };

describe('DataTable', () => {
    it('shows empty state', () => {
        const w = mount(DataTable, { props: { columns, rows: [], emptyTitle: 'Пусто' }, global: { stubs } });
        expect(w.text()).toContain('Пусто');
    });

    it('shows error with retry', async () => {
        const w = mount(DataTable, { props: { columns, rows: [], error: 'Сбой' }, global: { stubs } });
        expect(w.text()).toContain('Сбой');
        await w.find('button').trigger('click');
        expect(w.emitted('retry')).toHaveLength(1);
    });

    it('renders rows', () => {
        const w = mount(DataTable, { props: { columns, rows: [{ id: 1, name: 'ДЦ Северный' }] }, global: { stubs } });
        expect(w.text()).toContain('ДЦ Северный');
    });
});
