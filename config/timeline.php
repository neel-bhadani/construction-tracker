<?php

/*
 | The client's workbooks never live in the repository. The seeder, the
 | workbook:document command and the oracle tests read them from here.
 */
$workbooks = env('CLIENT_WORKBOOKS_DIR', '~/client-files/building-timeline/references');

return [
    'client_workbooks_dir' => str_starts_with($workbooks, '~/')
        ? rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME')), '/').substr($workbooks, 1)
        : $workbooks,
];
