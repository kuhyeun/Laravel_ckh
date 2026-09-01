<!-- UI Kit 갤러리 / 권한 레벨 0 전용
     현재 프로젝트에서 쓰는 공용 UI 부품(Select/Input/Button)을 한 곳에서 미리보고,
     사용 코드를 복사해 각 페이지에 붙여넣을 수 있는 페이지. -->

<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-3 border-b shrink-0">
            <h2 class="font-bold text-lg tracking-tight">UI Kit</h2>
            <span class="text-xs text-gray-400 hidden md:inline">부품을 복사해 각 페이지에 사용하세요</span>
            <div class="flex-1"></div>
            <nav class="flex gap-1 text-sm">
                <a
                    v-for="s in sections"
                    :key="s.id"
                    :href="'#' + s.id"
                    class="px-3 py-1 rounded-md text-gray-600 hover:bg-gray-100 transition-colors"
                >{{ s.label }}</a>
            </nav>
        </div>

        <!-- 본문 ( 내부 스크롤 ) -->
        <div class="flex-1 min-h-0 overflow-y-auto pt-5 pr-1 space-y-12">
            <!-- Select -->
            <section id="select" class="scroll-mt-4">
                <div class="flex items-baseline gap-3 mb-3">
                    <h3 class="text-base font-bold text-gray-800 tracking-tight">Select</h3>
                    <code class="text-xs text-gray-400">import BaseSelect from '@core/Components/Base/BaseSelect.vue'</code>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <ShowcaseCard title="기본 (md)" :code="code.selectBasic">
                        <BaseSelect v-model="demoVal" :options="demoOptions" class="w-56" />
                    </ShowcaseCard>
                    <ShowcaseCard title="사이즈 (sm / lg)" :code="code.selectSizes">
                        <BaseSelect size="sm" v-model="demoVal" :options="demoOptions" class="w-40" />
                        <BaseSelect size="lg" v-model="demoVal" :options="demoOptions" class="w-56" />
                    </ShowcaseCard>
                    <ShowcaseCard title="placeholder" :code="code.selectPlaceholder">
                        <BaseSelect v-model="emptyVal" :options="demoOptions" placeholder="선택하세요" class="w-56" />
                    </ShowcaseCard>
                    <ShowcaseCard title="비활성 (disabled)" :code="code.selectDisabled">
                        <BaseSelect v-model="demoVal" :options="demoOptions" disabled class="w-56" />
                    </ShowcaseCard>
                </div>
            </section>

            <!-- Input -->
            <section id="input" class="scroll-mt-4">
                <div class="flex items-baseline gap-3 mb-3">
                    <h3 class="text-base font-bold text-gray-800 tracking-tight">Input</h3>
                    <code class="text-xs text-gray-400">import BaseInput from '@core/Components/Base/BaseInput.vue'</code>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <ShowcaseCard title="기본 (md)" :code="code.inputBasic">
                        <BaseInput v-model="text" placeholder="값을 입력하세요" class="w-56" />
                    </ShowcaseCard>
                    <ShowcaseCard title="사이즈 (sm / lg)" :code="code.inputSizes">
                        <BaseInput size="sm" v-model="text" placeholder="sm" class="w-40" />
                        <BaseInput size="lg" v-model="text" placeholder="lg" class="w-56" />
                    </ShowcaseCard>
                    <ShowcaseCard title="숫자 (type=number)" :code="code.inputNumber">
                        <BaseInput type="number" v-model="num" class="w-40" />
                    </ShowcaseCard>
                    <ShowcaseCard title="비활성 (disabled)" :code="code.inputDisabled">
                        <BaseInput v-model="text" placeholder="비활성" disabled class="w-56" />
                    </ShowcaseCard>
                </div>
            </section>

            <!-- Button -->
            <section id="button" class="scroll-mt-4">
                <div class="flex items-baseline gap-3 mb-3">
                    <h3 class="text-base font-bold text-gray-800 tracking-tight">Button</h3>
                    <code class="text-xs text-gray-400">import BaseButton from '@core/Components/Base/BaseButton.vue'</code>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <ShowcaseCard title="유형 (variant)" :code="code.buttonVariants">
                        <BaseButton variant="primary">Primary</BaseButton>
                        <BaseButton variant="secondary">Secondary</BaseButton>
                        <BaseButton variant="danger">Danger</BaseButton>
                        <BaseButton variant="ghost">Ghost</BaseButton>
                    </ShowcaseCard>
                    <ShowcaseCard title="사이즈 (sm / md / lg)" :code="code.buttonSizes">
                        <BaseButton size="sm">Small</BaseButton>
                        <BaseButton size="md">Medium</BaseButton>
                        <BaseButton size="lg">Large</BaseButton>
                    </ShowcaseCard>
                    <ShowcaseCard title="비활성 (disabled)" :code="code.buttonDisabled">
                        <BaseButton disabled>Disabled</BaseButton>
                    </ShowcaseCard>
                </div>
            </section>

            <div class="h-4"></div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ShowcaseCard from '@core/Components/Base/ShowcaseCard.vue';

defineOptions({
    layout: AppLayout
});

// 데모용 상태
const demoOptions = [
    { value: 'a', label: '옵션 A' },
    { value: 'b', label: '옵션 B' },
    { value: 'c', label: '옵션 C' }
];
const demoVal = ref('a');
const emptyVal = ref('');
const text = ref('');
const num = ref(0);

// 복사용 코드 스니펫
const code = {
    selectBasic:
`<BaseSelect v-model="value" :options="options" class="w-56" />`,
    selectSizes:
`<BaseSelect size="sm" v-model="value" :options="options" class="w-40" />
<BaseSelect size="lg" v-model="value" :options="options" class="w-56" />`,
    selectPlaceholder:
`<BaseSelect v-model="value" :options="options" placeholder="선택하세요" class="w-56" />`,
    selectDisabled:
`<BaseSelect v-model="value" :options="options" disabled class="w-56" />`,

    inputBasic:
`<BaseInput v-model="text" placeholder="값을 입력하세요" class="w-56" />`,
    inputSizes:
`<BaseInput size="sm" v-model="text" placeholder="sm" class="w-40" />
<BaseInput size="lg" v-model="text" placeholder="lg" class="w-56" />`,
    inputNumber:
`<BaseInput type="number" v-model="num" class="w-40" />`,
    inputDisabled:
`<BaseInput v-model="text" placeholder="비활성" disabled class="w-56" />`,

    buttonVariants:
`<BaseButton variant="primary">Primary</BaseButton>
<BaseButton variant="secondary">Secondary</BaseButton>
<BaseButton variant="danger">Danger</BaseButton>
<BaseButton variant="ghost">Ghost</BaseButton>`,
    buttonSizes:
`<BaseButton size="sm">Small</BaseButton>
<BaseButton size="md">Medium</BaseButton>
<BaseButton size="lg">Large</BaseButton>`,
    buttonDisabled:
`<BaseButton disabled>Disabled</BaseButton>`
};

const sections = [
    { id: 'select', label: 'Select' },
    { id: 'input',  label: 'Input' },
    { id: 'button', label: 'Button' }
];
</script>
