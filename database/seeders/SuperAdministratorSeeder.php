<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Publishes the first super administrator with credentials owned by the
 * database instead of the environment.
 *
 * The password is written through the model's `hashed` cast, so only a hash is
 * ever stored. Re-running the seeder restores the credentials below, which is
 * how a deployment recovers access when the original password is unknown; edit
 * the constants once the owner rotates them.
 *
 * The account is seeded email-verified because the console sits behind the
 * `verified` middleware, and active because `EnsureUserIsActive` refuses
 * deactivated accounts. Neither flag is forced back on an existing account, so
 * deliberately deactivating the seeded owner survives a re-seed.
 */
class SuperAdministratorSeeder extends Seeder
{
    public const EMAIL = 'admin@exp-operations.local';

    public const NAME = 'Administrator';

    public const PASSWORD = 'ExpConsole#2026';

    public function run(): void
    {
        $admin = User::query()->firstOrNew(['email' => self::EMAIL]);

        $admin->name = self::NAME;
        $admin->password = self::PASSWORD;

        if ($admin->email_verified_at === null) {
            $admin->email_verified_at = now();
        }

        if (! $admin->exists) {
            $admin->active = true;
        }

        $admin->save();

        $admin->assignRole('Super Administrator');
    }
}
