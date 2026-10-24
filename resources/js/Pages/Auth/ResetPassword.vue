<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import FormField from '@/Components/FormField.vue'

const props = defineProps({
  token: { type: String, required: true },
  email: { type: String, default: '' },
})

const form = useForm({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
})

const submit = () => form.post('/reset-password', {
  onFinish: () => form.reset('password', 'password_confirmation'),
})
</script>

<template>
  <Head title="Choose a new password" />

  <AuthLayout>
    <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Choose a new password</h1>
    <p class="mb-7 text-sm text-slate-500">You will sign in with it from now on.</p>

    <form class="space-y-4" @submit.prevent="submit">
      <FormField label="Email" required :error="form.errors.email">
        <input v-model="form.email" type="email" autocomplete="username" />
      </FormField>

      <FormField label="New password" required hint="At least 8 characters." :error="form.errors.password">
        <input v-model="form.password" type="password" autocomplete="new-password" autofocus />
      </FormField>

      <FormField label="Confirm new password" required :error="form.errors.password_confirmation">
        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" />
      </FormField>

      <button type="submit" class="btn w-full py-2.5" :disabled="form.processing">
        {{ form.processing ? 'Saving…' : 'Save new password' }}
      </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
      <Link href="/forgot-password" class="font-semibold text-teal-700 hover:underline">Request a new link</Link>
    </p>
  </AuthLayout>
</template>
