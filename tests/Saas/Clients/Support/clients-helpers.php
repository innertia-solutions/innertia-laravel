<?php

if (! function_exists('bootClientsMode')) {
    /** Open mode + organizations (bootOrgOpenMode, creates tenant 'rancagua') + clients feature + clients migration. */
    function bootClientsMode(\Illuminate\Foundation\Application $app): void
    {
        bootOrgOpenMode($app);
        config()->set('innertia.saas.clients.enabled', true);
        (require __DIR__ . '/../../../../database/migrations/clients/2026_11_18_000001_create_clients_table.php')->up();
    }
}
