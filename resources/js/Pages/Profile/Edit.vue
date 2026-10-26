<script setup>
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'

/*
 | "My profile": the signed-in account's own details and password. Each form
 | saves on its own; the toast comes from the server's flash message.
 | Changing the password signs the account out everywhere else.
 */
const props = defineProps({ profile: Object })

const details = useForm({
  name: props.profile.name,
  email: props.profile.email,
  phone: props.profile.phone ?? '',
})

const saveDetails = () => details.patch('/profile', { preserveScroll: true })

const password = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const savePassword = () => password.put('/profile/password', {
  preserveScroll: true,
  onSuccess: () => password.reset(),
  onError: () => password.reset('current_password'),
})
</script>

<template>
  <AppLayout title="My profile">
    <div class="grid gap-5 md:grid-cols-2">
      <section class="card p-5 sm:p-6" aria-labelledby="details-heading">
        <h2 id="details-heading" class="mb-4 text-base font-semibold text-slate-900">Details</h2>
        <form class="space-y-4" @submit.prevent="saveDetails">
          <FormField label="Name" required :error="details.errors.name">
            <input v-model="details.name" type="text" autocomplete="name" />
          </FormField>
          <FormField label="Email" required :error="details.errors.email">
            <input v-model="details.email" type="email" autocomplete="email" />
          </FormField>
          <FormField label="Phone" hint="Optional." :error="details.errors.phone">
            <input v-model="details.phone" type="tel" autocomplete="tel" />
          </FormField>
          <button type="submit" class="btn w-full sm:w-auto" :disabled="details.processing">
            {{ details.processing ? 'Saving…' : 'Save details' }}
          </button>
        </form>
      </section>

      <section class="card p-5 sm:p-6" aria-labelledby="password-heading">
        <h2 id="password-heading" class="mb-4 text-base font-semibold text-slate-900">Change password</h2>
        <form class="space-y-4" @submit.prevent="savePassword">
          <FormField label="Current password" required :error="password.errors.current_password">
            <input v-model="password.current_password" type="password" autocomplete="current-password" />
          </FormField>
          <FormField label="New password" required hint="At least 8 characters." :error="password.errors.password">
            <input v-model="password.password" type="password" autocomplete="new-password" />
          </FormField>
          <FormField label="Confirm new password" required :error="password.errors.password_confirmation">
            <input v-model="password.password_confirmation" type="password" autocomplete="new-password" />
          </FormField>
          <p class="text-xs text-slate-500">You stay signed in here; every other device is signed out.</p>
          <button type="submit" class="btn w-full sm:w-auto" :disabled="password.processing">
            {{ password.processing ? 'Changing…' : 'Change password' }}
          </button>
        </form>
      </section>
    </div>
  </AppLayout>
</template>
