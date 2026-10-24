<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import FormField from '@/Components/FormField.vue'

/*
 | The same answer whether or not the address has an account, so this page
 | cannot be used to find out who does.
 */
defineProps({ status: String })

const form = useForm({ email: '' })

const submit = () => form.post('/forgot-password')
</script>

<template>
  <Head title="Forgot password" />

  <AuthLayout>
    <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Forgot your password?</h1>
    <p class="mb-7 text-sm text-slate-500">
      Enter your email address and we will send you a link to choose a new one.
    </p>

    <div v-if="status" class="info-box mb-4 !text-sm" role="status">{{ status }}</div>

    <form class="space-y-4" @submit.prevent="submit">
      <FormField label="Email" required :error="form.errors.email">
        <input v-model="form.email" type="email" autocomplete="username" autofocus />
      </FormField>

      <button type="submit" class="btn w-full py-2.5" :disabled="form.processing">
        {{ form.processing ? 'Sending…' : 'Email reset link' }}
      </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
      Remembered it?
      <Link href="/login" class="font-semibold text-teal-700 hover:underline">Back to sign in</Link>
    </p>
  </AuthLayout>
</template>
