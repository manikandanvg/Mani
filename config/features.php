<?php

/*
 * Feature switches for modules the board has parked. Everything stays built and
 * routable; only the navigation entries disappear while a switch is off.
 */
return [
    // Monthly Tasks (duties / targets / scores / stock tracking) are planned together with the
    // L-BOX roll-out, which is delayed — keep the module idle (board 2026-10-31, common 6).
    'monthly_tasks' => (bool) env('MONTHLY_TASKS_ENABLED', false),
];
