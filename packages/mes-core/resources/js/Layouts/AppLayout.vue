<template>
    <div class="flex flex-col h-screen">
        <header class="border-b">
            <TopFrame></TopFrame>
        </header>

        <div class="flex flex-1 overflow-hidden">
            <aside id="app-sidebar" class="app-sidebar w-[250px] border-r transition-all duration-300 ease-in-out overflow-y-auto overflow-x-hidden">
                <LeftMenu :menu-items="menuItems" :admin-items="adminItems"></LeftMenu>
            </aside>

            <main class="app-content flex flex-col flex-1 overflow-y-auto p-6 pt-[10px] bg-white">
                <slot></slot>
            </main>
        </div>
        
        <!-- <footer class="app-footer border-t">
            <BottomFrame></BottomFrame>
        </footer> -->

        <BaseModal></BaseModal>
        <SessionWatcher></SessionWatcher>
    </div>
</template>

<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TopFrame from '../Components/Layout/TopFrame.vue';
import LeftMenu from '../Components/Layout/LeftMenu.vue';
import BottomFrame from '../Components/Layout/BottomFrame.vue';
import BaseModal from '../Components/Modals/BaseModal.vue';
import SessionWatcher from '../Components/Layout/SessionWatcher.vue';

const page = usePage();
const menuItems = computed(() => page.props.userMenu ?? []);
const adminItems = computed(() => page.props.adminMenu ?? []);
</script>