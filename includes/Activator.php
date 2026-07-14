<?php

declare(strict_types=1);

namespace DSAP;

final class Activator
{
    public static function activate(): void
    {
        Database::createTables();
        Settings::ensureDefaults();
        Scheduler::scheduleEvents();
    }

    public static function deactivate(): void
    {
        Scheduler::clearEvents();
    }

    public static function maybeUpgrade(): void
    {
        if ((string) get_option('dsap_db_version', '') !== Database::DB_VERSION) {
            Database::createTables();
            Settings::ensureDefaults();
        }
    }
}
