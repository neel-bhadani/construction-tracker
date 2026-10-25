<script setup>
/*
 | The signed-out pages: the CRM's split screen. The left panel says what the
 | product is and nothing about the project — no figures, no dates. Anyone can
 | load this page, and the schedule is exactly what signing in protects.
 */
</script>

<template>
  <div class="flex min-h-screen">

    <!-- left: image panel, hidden on phones -->
    <div class="relative hidden w-[52%] flex-col justify-between overflow-hidden bg-slate-900 p-12 text-white md:flex">
      <div
        class="absolute inset-0 opacity-[0.06]"
        style="background-image:linear-gradient(#fff 1px,transparent 1px),linear-gradient(90deg,#fff 1px,transparent 1px);background-size:44px 44px"
      />
      <div class="absolute -bottom-40 -right-36 h-[520px] w-[520px] rounded-full
                  bg-[radial-gradient(circle_at_30%_30%,rgba(15,118,110,.55),transparent_62%)]" />

      <div class="relative flex items-center gap-2.5 font-bold">
        <img src="/images/brand/shaligram-mark.png" alt="" class="h-7 w-7 shrink-0" /> Shaligram Timeline
      </div>

      <div class="relative">
        <h2 class="mb-4 max-w-[15ch] text-4xl font-bold leading-tight tracking-tight">
          Every milestone, measured against the plan.
        </h2>
        <p class="max-w-[44ch] text-slate-300">
          Record each milestone as it is reached, see how far ahead or behind the schedule is running,
          and know where the handover date is heading.
        </p>
      </div>

      <div class="relative flex gap-9 border-t border-white/15 pt-7 text-sm text-slate-400">
        <div>Planned against actual</div>
        <div>Three forecasts</div>
        <div>Every change recorded</div>
      </div>
    </div>

    <!-- right: the forms -->
    <div class="flex flex-1 items-center justify-center bg-white px-6 py-10">
      <div class="w-full max-w-sm">
        <!-- the full lockup only ever sits on white: its tagline is black and vanishes on navy -->
        <img src="/images/brand/shaligram-logo.png" alt="Shaligram — Promising Excellence"
             class="mx-auto mb-8 h-auto w-full max-w-[220px]" />

        <slot />
      </div>
    </div>
  </div>
</template>
