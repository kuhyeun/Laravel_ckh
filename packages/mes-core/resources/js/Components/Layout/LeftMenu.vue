<template>
    <div class="w-[250px] whitespace-nowrap">
        <nav class="p-3">
            <ul>
                <MenuItem
                v-for="item in menuItems"
                :key="item.menu_idx ?? item.id ?? item.menu_route_name ?? item.menu_name"
                :menu-item="item">
                </MenuItem>
            </ul>

            <!-- 시스템(관리자) 메뉴 별도 그룹 -->
            <div v-if="adminItems.length" class="mt-4 pt-3 border-t border-gray-200">
                <p class="px-1 mb-1 text-[11px] font-semibold tracking-wide text-gray-400">관리자</p>
                <ul>
                    <MenuItem
                    v-for="item in adminItems"
                    :key="item.menu_idx ?? item.id ?? item.menu_route_name ?? item.menu_name"
                    :menu-item="item">
                    </MenuItem>
                </ul>
            </div>
        </nav>
    </div>
</template>

<script setup>
import MenuItem from './MenuItem.vue';

const props = defineProps({
    menuItems: {
        type: Array,
        default: () => [],
    },
    adminItems: {
        type: Array,
        default: () => [],
    },
});
</script>
