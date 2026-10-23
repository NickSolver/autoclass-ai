<script setup lang="ts">
import type { ExtraIconName, IconName } from '@/navigation';

type Name = IconName | ExtraIconName;

defineProps<{ name: Name; size?: number }>();

// Контурные иконки 24×24, stroke = currentColor.
const paths: Record<Name, string> = {
    home: 'M3 10.5 12 3l9 7.5M5 9.5V21h5v-6h4v6h5V9.5',
    phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z',
    upload: 'M12 16V4m0 0-4 4m4-4 4 4M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3',
    inbox: 'M3 13h5l1.5 3h5L16 13h5M5 5h14l2 8v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6z',
    scale: 'M12 3v18M7 21h10M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zm14 0-3 7a3 3 0 0 0 6 0z',
    chart: 'M4 20V10m6 10V4m6 16v-7m4 7H2',
    flow: 'M6 3h4v4H6zM14 17h4v4h-4zM6 17h4v4H6zM8 7v4h8v6M8 11v6',
    checklist: 'M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2',
    plug: 'M9 3v5m6-5v5M6 8h12v3a6 6 0 0 1-12 0zm6 9v4',
    cpu: 'M7 7h10v10H7zM10 3v4m4-4v4m-4 10v4m4-4v4M3 10h4m-4 4h4m10-4h4m-4 4h4',
    building: 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16m0-10h2a2 2 0 0 1 2 2v8M2 21h20M8 7h4m-4 4h4m-4 4h4',
    store: 'M4 9 5.5 4h13L20 9M4 9v11h16V9M4 9h16M9 20v-6h6v6',
    layers: 'm12 3 9 5-9 5-9-5zm-9 9 9 5 9-5M3 16l9 5 9-5',
    people: 'M16 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm13 9v-1a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
    user: 'M20 21v-1a5 5 0 0 0-5-5H9a5 5 0 0 0-5 5v1M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    shield: 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6zm-3 9 2 2 4-4',
    log: 'M7 3h8l4 4v14H7zM15 3v4h4M10 12h6m-6 4h6',
    tree: 'M12 3v4m0 0H6v4m6-4h6v4M4 11h4v4H4zm6 0h4v4h-4zm6 0h4v4h-4zM6 15v3h0',
    'chevron-right': 'm9 6 6 6-6 6',
    'chevron-down': 'm6 9 6 6 6-6',
    logout: 'M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5m5 5H3',
    menu: 'M4 6h16M4 12h16M4 18h16',
    x: 'M6 6l12 12M18 6 6 18',
    plus: 'M12 5v14M5 12h14',
    search: 'm21 21-4.3-4.3M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z',
    lock: 'M6 11h12v10H6zm2 0V8a4 4 0 0 1 8 0v3',
    alert: 'M12 9v4m0 4h.01M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    check: 'm5 12 5 5L20 7',
    clock: 'M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    key: 'M15 7a4 4 0 1 1-3.9 5H3v3h3v3h3v-3h2.1A4 4 0 0 1 15 7zm1 3h.01',
    play: 'M7 4.5v15l12-7.5z',
    download: 'M12 4v12m0 0-4-4m4 4 4-4M4 18v1a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1',
    refresh: 'M20 11a8 8 0 0 0-14.9-3M4 4v4h4m-4 5a8 8 0 0 0 14.9 3M20 20v-4h-4',
    ban: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM5.6 5.6l12.8 12.8',
};
</script>

<template>
    <svg
        :width="size ?? 18" :height="size ?? 18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0"
    >
        <path :d="paths[name]" />
    </svg>
</template>
