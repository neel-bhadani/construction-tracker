<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'

/*
 | How to use the app, in plain words, one example per section. The wording of
 | the adjustment hint matches the activity dialog's exactly.
 */
const sections = [
  { id: 'add', title: 'Add an activity' },
  { id: 'dates', title: 'Planned date and actual date' },
  { id: 'insert', title: 'Insert an activity between two others' },
  { id: 'breaks', title: 'Breaks' },
  { id: 'floors', title: 'Floors' },
  { id: 'chart', title: 'The chart lines' },
  { id: 'profile', title: 'My profile' },
]
</script>

<template>
  <AppLayout title="Help">
    <div class="grid gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
      <nav class="hidden lg:block" aria-label="On this page">
        <ul class="sticky top-20 space-y-1 text-sm">
          <li v-for="s in sections" :key="s.id">
            <a :href="`#${s.id}`" class="block rounded px-2 py-1 text-slate-600 hover:bg-white hover:text-teal-700">{{ s.title }}</a>
          </li>
        </ul>
      </nav>

      <div class="help max-w-3xl space-y-5 text-sm leading-relaxed text-slate-700">
        <section id="add" class="card p-5 sm:p-6">
          <h2>Add an activity</h2>
          <p>Open a building and press <b>Add activity</b>. Give it a name, choose what it <b>comes after</b>, and type its
            <b>planned date</b>. Everything else is optional.</p>
          <p class="example"><b>Example:</b> name “Lift installation”, comes after “Flooring”, planned date 20 Mar 2028.
            Before you save, the box at the bottom says which other dates move, if any.</p>
        </section>

        <section id="dates" class="card p-5 sm:p-6">
          <h2>Planned date and actual date</h2>
          <p>The <b>planned date</b> is when the work should be done. Next to it is <b>“N days after …”</b>: the same
            thing counted from the activity it comes after. Type either one and the other follows. If you move an
            activity's date, everything that comes after it moves too.</p>
          <p>The <b>actual date</b> is when it really was done. Leave it empty until then. It can't be in the future or
            before the building's actual start. The <b>Actual</b> button on each row is the quick way to record it.</p>
          <p><b>Ahead / behind</b> is planned minus actual: +3 means it finished 3 days early, −2 means 2 days late.</p>
          <p class="example"><b>Example:</b> “3rd SLAB” is planned for 15 Feb and was finished on 12 Feb: it shows +3.</p>
          <p>Some activities carry a fixed shift copied from the old Excel sheet. The dialog then says, for example,
            “Includes +17 days adjustment”, with a <b>Clear</b> link to remove it. When the activity you choose under
            “Comes after” has one, the dialog says: “DIWALI's date includes +17 days adjustment, which doesn't carry
            over.” The shift moves only that one activity's date, not the ones after it.</p>
        </section>

        <section id="insert" class="card p-5 sm:p-6">
          <h2>Insert an activity between two others</h2>
          <p>Open the <b>⋯</b> menu on a row and choose <b>Insert activity below</b> (on a computer, a small <b>+</b> also
            shows under a row when you point at it). The new activity goes right under that row. Then choose:</p>
          <ul>
            <li><b>Push the activities after it later</b> (the usual choice): the next activity now comes after the new one,
              so it and everything after it move later by the new activity's days.</li>
            <li><b>Don't move other activities:</b> nothing else changes.</li>
          </ul>
          <p class="example"><b>Example:</b> insert “Curing, 7 days” under “2nd SLAB” with push: “3rd SLAB” and every later
            date move 7 days, and so does the finish. Actual dates you've recorded never change. If the row below
            doesn't come after the row you chose, the dialog says “Nothing to push”.</p>
        </section>

        <section id="breaks" class="card p-5 sm:p-6">
          <h2>Breaks</h2>
          <p>Tick <b>This is a break</b> for Diwali, Holi or another festival. A break pushes the dates after it like any
            other activity, but it is never “done”: it has no actual date and is on no floor.</p>
          <p class="example"><b>Example:</b> “Diwali break, 20 days” after “1st SLAB”: the 2nd slab's planned date moves 20 days later.</p>
        </section>

        <section id="floors" class="card p-5 sm:p-6">
          <h2>Floors</h2>
          <p>Floors are optional. On a building page, press <b>+ Add floors</b>: add one floor by name, or the quick
            “Ground + 1 to 13”, which shows which floors it will add before you save. Then choose the floor in an
            activity's dialog, or use <b>Move to floor</b> in a row's ⋯ menu. Activities on no floor are
            <b>Whole building</b> (masonry, handover…).</p>
          <p>The <b>Floors</b> section shows each floor's progress, how many activities are done, and when it should and will
            finish. Click a floor, or one of the chips above the list, to see only its activities; click again to see
            all. Deleting a floor moves its activities to Whole building; no dates change.</p>
          <p class="example"><b>Example:</b> “1st floor: 1 of 2 done, Behind” means one of its activities is done and another
            is past its planned date without an actual date.</p>
          <p><b>Why the floor percentages don't add up to the building's:</b> the building's % is how far along its plan the
            work has got, while a floor's % adds up only that floor's own work, and breaks and whole-building work
            belong to no floor.</p>
        </section>

        <section id="chart" class="card p-5 sm:p-6">
          <h2>The chart lines</h2>
          <ul>
            <li><b>Planned</b>: the % of work that should be done by each date.</li>
            <li><b>Actual</b>: the % really done, from the actual dates you record.</li>
            <li><b>Hold current lead</b>: the finish if you stay as far ahead or behind as you are today.</li>
            <li><b>Average pace</b> and <b>Recent pace</b> (tick them to show): the finish at the speed since the start,
              or since the last two actual dates.</li>
          </ul>
          <p class="example"><b>Example:</b> if Actual is above Planned, the building is ahead of plan.</p>
        </section>

        <section id="profile" class="card p-5 sm:p-6">
          <h2>My profile</h2>
          <p>Open the menu under your name at the top right and choose <b>My profile</b>. Change your name, email or phone, or
            your password. To change the password you type your current one first; every other phone or computer signed
            in to your account is then signed out.</p>
          <p class="example"><b>Example:</b> you lost a phone that was signed in: change your password, and that phone is signed out.</p>
        </section>
      </div>
    </div>
  </AppLayout>
</template>

<style scoped>
.help h2 { margin-bottom: 0.5rem; font-size: 1rem; font-weight: 600; color: #0f172a; }
.help p + p, .help ul + p, .help p + ul { margin-top: 0.5rem; }
.help ul { list-style: disc; padding-left: 1.25rem; }
.help li + li { margin-top: 0.25rem; }
.help .example { margin-top: 0.75rem; border-radius: 0.5rem; background: #f8fafc; padding: 0.5rem 0.75rem; color: #475569; }
</style>
