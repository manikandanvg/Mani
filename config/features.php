<?php

/*
 * Feature switches for modules the board has parked. Everything stays built and
 * routable; only the navigation entries disappear while a switch is off.
 */
return [
    // Monthly Tasks (duties / targets / scores / stock tracking) are planned together with the
    // L-BOX roll-out, which is delayed — keep the module idle (board 2026-10-31, common 6).
    'monthly_tasks' => (bool) env('MONTHLY_TASKS_ENABLED', false),

    // User 2026-11-11: until the branch wiring is confirmed, EVERY branch orders from Head
    // Office only — no dealer is anybody's supplier. While on: no dealer approves an order,
    // no stock ships from a dealer, the Branch form / Order Source Request / auto-map cannot
    // point a branch at another dealer. Set HQ_ONLY_SOURCING=false to bring the dealer
    // supply chain (board matrix 2026-08-26) back.
    'hq_only_sourcing' => (bool) env('HQ_ONLY_SOURCING', true),
];
