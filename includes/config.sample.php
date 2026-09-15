<?php
/**
 * Sample database configuration.
 *
 * The setup wizard (install.php) writes includes/config.php for this machine.
 * That file is gitignored so database paths and passwords are never pushed.
 *
 * Use this sample only to configure by hand: copy it to includes/config.php
 * and fill in your own values. Do not commit the copy.
 */
return [
    'db_driver'   => 'pgsql',
    'db_host'     => 'localhost',
    'db_port'     => '5432',
    'db_name'     => 'histopath_system',
    'db_user'     => 'histopath_app',
    'db_password' => 'the_password_you_set_when_creating_the_database',
];
