<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import FormField from '@/Components/FormField.vue'

/*
 | One page, two tabs. /login opens it on Sign in and /register on Register,
 | so each tab has an address of its own: a failed registration redirects back
 | to the tab it came from.
 |
 | The tab switch is a visit with preserve-state, so the component — and both
 | forms with whatever was typed into them — survives the move between the two.
 |
 | With registration closed (canRegister false) there are no tabs and nothing
 | on the page mentions signing up; /register itself answers 404.
 */
const props = defineProps({
  status: String,
  tab: { type: String, default: 'signin' },
  canRegister: Boolean,
})

const isRegister = computed(() => props.canRegister && props.tab === 'register')

const tabs = [
  { key: 'signin', label: 'Sign in', href: '/login' },
  { key: 'register', label: 'Register', href: '/register' },
]

/* ---------------- sign in ---------------- */

const showPw = ref(false)

const login = useForm({
  email: '',
  password: '',
  remember: true,
})

const submitLogin = () => login.post('/login', {
  onFinish: () => login.reset('password'),
})

/* ---------------- register ---------------- */

const register = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const submitRegister = () => register.post('/register', {
  onFinish: () => register.reset('password', 'password_confirmation'),
})
</script>

<template>
  <Head :title="isRegister ? 'Register' : 'Sign in'" />

  <AuthLayout>
    <div v-if="canRegister" class="mb-7 flex gap-1 border-b border-slate-100" role="tablist">
      <Link v-for="t in tabs" :key="t.key" :href="t.href" preserve-state
            role="tab" :aria-selected="tab === t.key"
            class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
            :class="tab === t.key
              ? 'border-teal-700 font-semibold text-slate-900'
              : 'border-transparent text-slate-500 hover:text-slate-700'">
        {{ t.label }}
      </Link>
    </div>

    <!-- ================= sign in ================= -->
    <template v-if="!isRegister">
      <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Sign in</h1>
      <p class="mb-7 text-sm text-slate-500">Sign in with your email address and password.</p>

      <div v-if="status" class="mb-4 text-sm font-medium text-teal-700">{{ status }}</div>

      <form class="space-y-4" @submit.prevent="submitLogin">

        <FormField label="Email" required :error="login.errors.email">
          <input v-model="login.email" type="email" autocomplete="username" autofocus />
        </FormField>

        <FormField label="Password" required :error="login.errors.password">
          <div class="relative">
            <input v-model="login.password" :type="showPw ? 'text' : 'password'" autocomplete="current-password" />
            <button
              type="button"
              class="absolute right-2 top-1/2 -translate-y-1/2 px-2 text-xs text-slate-400"
              @click="showPw = !showPw"
            >{{ showPw ? 'Hide' : 'Show' }}</button>
          </div>
        </FormField>

        <div class="flex items-center justify-between">
          <label class="flex items-center gap-2 text-sm text-slate-500">
            <input v-model="login.remember" type="checkbox" class="!min-h-0 !w-auto rounded" />
            Keep me signed in
          </label>
          <Link href="/forgot-password" class="text-sm font-semibold text-teal-700 hover:underline">
            Forgot password?
          </Link>
        </div>

        <button type="submit" class="btn w-full py-2.5" :disabled="login.processing">
          {{ login.processing ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>

      <p v-if="canRegister" class="mt-6 text-center text-sm text-slate-500">
        New here?
        <Link href="/register" preserve-state class="font-semibold text-teal-700 hover:underline">
          Create an account
        </Link>
      </p>
    </template>

    <!-- ================= register ================= -->
    <template v-else>
      <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Create an account</h1>
      <p class="mb-7 text-sm text-slate-500">You are signed in as soon as it is created.</p>

      <!-- the rate limit: about the whole form, not one field -->
      <div v-if="register.errors.register" class="warn-box mb-4 !text-sm" role="alert">
        {{ register.errors.register }}
      </div>

      <form class="space-y-4" @submit.prevent="submitRegister">
        <FormField label="Name" required :error="register.errors.name">
          <input v-model="register.name" type="text" autocomplete="name" />
        </FormField>

        <FormField label="Email" required :error="register.errors.email">
          <input v-model="register.email" type="email" autocomplete="email" />
        </FormField>

        <FormField label="Password" required hint="At least 8 characters." :error="register.errors.password">
          <input v-model="register.password" type="password" autocomplete="new-password" />
        </FormField>

        <FormField label="Confirm password" required :error="register.errors.password_confirmation">
          <input v-model="register.password_confirmation" type="password" autocomplete="new-password" />
        </FormField>

        <button type="submit" class="btn w-full py-2.5" :disabled="register.processing">
          {{ register.processing ? 'Creating account…' : 'Create account' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-slate-500">
        Already have an account?
        <Link href="/login" preserve-state class="font-semibold text-teal-700 hover:underline">
          Sign in
        </Link>
      </p>
    </template>
  </AuthLayout>
</template>
