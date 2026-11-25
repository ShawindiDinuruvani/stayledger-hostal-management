<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class InspectSharedUsersSchema extends Command
{
    protected $signature = 'stayledger:inspect-shared-users';

    protected $description = 'Read-only assessment of the shared Neon PostgreSQL users table';

    public function handle(): int
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'pgsql') {
                $this->error('Not connected to PostgreSQL. No shared Neon checks performed.');
                return self::FAILURE;
            }

            $connection->getPdo();

            if (! Schema::hasTable('users')) {
                $this->error('The users table is missing. Do not run full Laravel migrations against a shared database.');
                return self::FAILURE;
            }

            $columns = Schema::getColumnListing('users');
            $expected = ['id', 'name', 'email', 'password', 'role', 'is_active'];
            $missing = array_values(array_diff($expected, $columns));
            $this->info('PostgreSQL users table: found.');
            $this->line('Missing required columns: '.($missing ? implode(', ', $missing) : 'none'));

            $uniqueEmail = false;
            foreach (Schema::getIndexes('users') as $index) {
                if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['email']) {
                    $uniqueEmail = true;
                    break;
                }
            }

            $this->line('Unique email index: '.($uniqueEmail ? 'present' : 'NOT CONFIRMED'));

            $check = $connection->selectOne(<<<'SQL'
SELECT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conrelid = to_regclass('public.users')
      AND conname = 'stayledger_users_role_check'
) AS present
SQL);
            $this->line('User role CHECK constraint: '.(($check && $check->present) ? 'present' : 'not present'));

            if ($missing || ! $uniqueEmail) {
                $this->error('Schema not yet ready for this module. Coordinate a reviewed migration.');
                return self::FAILURE;
            }

            $this->info('Schema has the required fields and email uniqueness.');
            $this->line('Read-only inspection complete; no database records were changed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Never echo raw database exceptions: connection strings may contain credentials.
            $this->error('Unable to inspect shared users schema. Check the local DB settings and network.');
            return self::FAILURE;
        }
    }
}
