<?php

namespace Database\Seeders\Concerns;

/**
 * Demo and development seeders write fixed rows and weak default passwords
 * with updateOrCreate. They must never run against a live (staging or
 * production) database, whatever invokes them.
 */
trait ProtectsLiveData
{
    protected function isLocalOrTesting(): bool
    {
        return app()->environment('local', 'testing');
    }

    /** True (after saying why) when this seeder must not run here. */
    protected function refuseOutsideLocal(string $alternative): bool
    {
        if ($this->isLocalOrTesting()) {
            return false;
        }

        $this->command?->warn(static::class . " only runs in local and testing environments, so it did nothing. {$alternative}");

        return true;
    }
}
