# Deployment and first-install provisioning

## What a deploy does

`railway.toml` runs one release command on every deploy:

```
php artisan migrate --force
```

It changes the database schema and nothing else. It does not seed. Anything run
from the release command runs on **every** deploy, so a seeder there could
overwrite what the owner manages in the app: truck type rates, capacity, class,
status and descriptions, and the Owner account's email, password, status and
profile. Do not add seeders to the release command.

## Setting up a new environment (once)

1. Set the environment variables on the new service:
   - `SUPERADMIN_PASSWORD`: the Owner's first password, at least 12 characters.
     Required; one is never generated, because there would be no way to tell you what it is.
   - `SUPERADMIN_EMAIL` and `SUPERADMIN_NAME`: optional. They default to
     `superadmin@gmail.com` and `System SuperAdmin`.
2. Deploy, so `php artisan migrate --force` creates the schema.
3. Run the provisioning command once, from a shell on the service:

   ```
   php artisan towmate:provision
   ```

   - Add `--ask-password` to type the Owner password at a hidden prompt instead of
     reading `SUPERADMIN_PASSWORD`.
   - Add `--vehicle-types` to also load the starting vehicle types (only when there are none).
4. Log in as the Owner and change the password if it was shared.

The command prints what it did ("created", "already exists, left unchanged") and
never prints a password.

## What provisioning will and will not do

It is insert-only, so running it again is safe:

| Data | Behaviour |
|---|---|
| Owner account | Created only if **no** Owner account exists (any email, any status). An existing Owner is never changed. |
| Truck types | The three starting types are created only when the truck types table is **empty**. Existing types are never edited, re-enabled or recreated, even if an owner renamed or disabled them. |
| Vehicle types (`--vehicle-types`) | Loaded only when there are none. |

To reset a forgotten Owner password, use the application's password recovery
flow. Provisioning will not change an existing account.

## Seeders

`php artisan db:seed` (the `DatabaseSeeder`) and the demo seeders
(`DispatcherSeeder`, `TeamLeaderSeeder`, `UnitSeeder`, `VehicleTypeSeeder` on a
non-empty table) refuse to run outside `local` and `testing`. They use fixed
rows and weak default passwords and are for development only.

`SuperAdminSeeder` and `TruckTypeSeeder` are insert-only everywhere except
`local`/`testing`, where `SuperAdminSeeder` still creates or resets the
development account from `SUPERADMIN_*`, which the test suite relies on.
