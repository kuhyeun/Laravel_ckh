<template>
    <div>
        <hr class="mt-2 mb-2">
        <p class="mb-1">
            <strong>이름:</strong> {{ user.name }}
        </p>
        <p class="mb-1">
            <strong>이메일:</strong> {{ user.email }}
        </p>
        <p class="mb-1">
            <strong>가입일:</strong> {{ user.joined }}
        </p>
    </div>
</template>

<script setup>

defineProps({
    user: {
        type: Object,
        required: true,
    }
});

</script>
