<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import FormField from '@/Components/FormField.vue'

/*
 | One page, two tabs. /login opens it on Sign in and /signup on Sign up, so
 | each tab has an address of its own: a failed sign-up redirects back to the
 | tab it came from, and a link to /signup lands on the right form.
 |
 | The tab switch is a visit with preserve-state, so the component — and both
 | forms with whatever was typed into them — survives the move between the two.
 |
 | Sign up asks for an account, it does not make one that works. The server
 | writes it as a viewer awaiting approval, whatever this form sends.
 */
const props = defineProps({
  status: String,
  tab: { type: String, default: 'signin' },
})

const isSignup = computed(() => props.tab === 'signup')

const tabs = [
  { key: 'signin', label: 'Sign in', href: '/login' },
  { key: 'signup', label: 'Sign up', href: '/signup' },
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

/* ---------------- sign up ---------------- */

const signup = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const submitSignup = () => signup.post('/signup', {
  onFinish: () => signup.reset('password', 'password_confirmation'),
})
</script>

<template>
  <Head :title="isSignup ? 'Sign up' : 'Sign in'" />

  <AuthLayout>
    <div class="mb-7 flex gap-1 border-b border-slate-100" role="tablist">
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
    <template v-if="!isSignup">
      <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Sign in</h1>
      <p class="mb-7 text-sm text-slate-500">Use the email address your account was approved with.</p>

      <div v-if="status" class="mb-4 text-sm font-medium text-teal-700">{{ status }}</div>

      <!--
        Waiting for approval. Not a red line under the email field: the person
        typed nothing wrong, and a field error would send them round retyping a
        password that was right.
      -->
      <div v-if="login.errors.approval" class="warn-box mb-4 !text-sm" role="status">
        {{ login.errors.approval }} An administrator has to approve it before you can sign in.
      </div>

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

      <p class="mt-6 text-center text-sm text-slate-500">
        New here?
        <Link href="/signup" preserve-state class="font-semibold text-teal-700 hover:underline">
          Request an account
        </Link>
      </p>
    </template>

    <!-- ================= sign up ================= -->
    <template v-else>
      <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Request an account</h1>
      <p class="mb-7 text-sm text-slate-500">
        An administrator approves every new account. You can sign in once they have.
      </p>

      <!-- the rate limit: about the whole form, not one field -->
      <div v-if="signup.errors.signup" class="warn-box mb-4 !text-sm" role="alert">
        {{ signup.errors.signup }}
      </div>

      <form class="space-y-4" @submit.prevent="submitSignup">
        <FormField label="Name" required :error="signup.errors.name">
          <input v-model="signup.name" type="text" autocomplete="name" />
        </FormField>

        <FormField label="Email" required :error="signup.errors.email">
          <input v-model="signup.email" type="email" autocomplete="email" />
        </FormField>

        <FormField label="Password" required hint="At least 8 characters." :error="signup.errors.password">
          <input v-model="signup.password" type="password" autocomplete="new-password" />
        </FormField>

        <FormField label="Confirm password" required :error="signup.errors.password_confirmation">
          <input v-model="signup.password_confirmation" type="password" autocomplete="new-password" />
        </FormField>

        <button type="submit" class="btn w-full py-2.5" :disabled="signup.processing">
          {{ signup.processing ? 'Sending request…' : 'Request account' }}
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
