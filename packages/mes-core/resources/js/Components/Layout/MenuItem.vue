<template>
    <li class="mt-1">
        <div
            class="flex items-center justify-between h-[32px] px-1 rounded font-inter font-medium hover:bg-gray-200 hover:text-[#2B2D31]"
            :class="[
                isActive ? 'bg-gray-200 text-[#2B2D31]' : 'text-[#6C717A]',
                { 'cursor-pointer': hasChildren }
            ]"
            @click="hasChildren ? toggle() : null"
        >
            <component v-if="menuIcon" :is="menuIcon" class="h-5 w-5 mr-2" />
            
            <span v-if="hasChildren" class="flex-grow flex items-center text-[14px]">
                {{ menuItem.menu_name }}
            </span>
            <Link v-else-if="itemHref" :href="itemHref" class="h-full flex-grow flex items-center text-[14px]">
                {{ menuItem.menu_name }}
            </Link>
            <span v-else class="flex-grow flex items-center text-[14px] cursor-pointer">
                {{ menuItem.menu_name }}
            </span>

            <span v-if="hasChildren" class="icon-wrapper">
                <svg
                    class="w-4 h-4 transform transition-transform duration-300 ease-in-out"
                    :class="{ 'rotate-90': expanded }"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 5l7 7-7 7"
                    />
                </svg>
            </span>
        </div>

        <ul
            v-if="hasChildren"
            ref="subMenuRef"
            class="sub-menu overflow-hidden pl-4 ml-2"
            :style="{ maxHeight: subMenuMaxHeight, transition: 'max-height 0.3s ease' }"
        >
            <MenuItem
                v-for="child in menuItem.children"
                :key="child.menu_idx ?? child.id ?? child.menu_route_name ?? child.menu_name"
                :menu-item="child"
            >
            </MenuItem>
        </ul>
    </li>
</template>

<script setup>
import { ref, computed, nextTick, watch, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import MenuItem from './MenuItem.vue';
import * as OutlineIcons from '@heroicons/vue/24/outline';

const page = usePage();
const currentUrl = ref( page.url );

let removeListener = null;

onMounted(() => {
    removeListener = router.on('navigate', (event) => {
        currentUrl.value = event.detail.page.url;
    });
});

onUnmounted(() => {
    removeListener?.();
});

const props = defineProps({
    menuItem: {
        type: Object,
        required: true,
    },
});

const hasChildren = computed(() =>
    Array.isArray(props.menuItem.children) && props.menuItem.children.length > 0
);

const isActive = computed(() => {
    const path = props.menuItem.url_path;
    if (!path) return false;
    return currentUrl.value === path || currentUrl.value === path + '/';
});

const hasActiveChild = (item) => {
    if (!Array.isArray(item.children)) return false;
    return item.children.some(child => {
        if (child.url_path) {
            if (currentUrl.value === child.url_path || currentUrl.value === child.url_path + '/') return true;
        }
        return hasActiveChild(child);
    });
};

const menuIcon = computed(() =>
    OutlineIcons[props.menuItem.menu_icon] ?? null
);

const shouldExpand = () => hasChildren.value && hasActiveChild(props.menuItem);
const expanded = ref(shouldExpand());
const subMenuRef = ref(null);
const subMenuMaxHeight = ref(expanded.value ? 'none' : '0px');

watch(currentUrl, () => {
  if (hasChildren.value) {
    const active = hasActiveChild(props.menuItem);
    if (active && !expanded.value) {
        expanded.value = true;
        subMenuMaxHeight.value = 'none';
    } else if (!active && expanded.value) {
        subMenuMaxHeight.value = subMenuRef.value?.scrollHeight + 'px';
        requestAnimationFrame(() => {
            subMenuMaxHeight.value = '0px';
        });
        expanded.value = false;
    }
  }
});

const itemHref = computed(() => props.menuItem.url_path || null);

const toggle = async () => {
    if (!expanded.value) {
        expanded.value = true;
        await nextTick();
        const height = subMenuRef.value?.scrollHeight ?? 0;
        subMenuMaxHeight.value = height + 'px';
        // 트랜지션 후 none으로 변경하여 자식 메뉴 확장 허용
        setTimeout(() => {
            subMenuMaxHeight.value = 'none';
        }, 300);
    } else {
        // 닫을 때: 현재 높이를 설정한 뒤 0으로 전환해야 트랜지션 작동
        subMenuMaxHeight.value = subMenuRef.value?.scrollHeight + 'px';
        requestAnimationFrame(() => {
            subMenuMaxHeight.value = '0px';
        });
        expanded.value = false;
    }
};
</script>
