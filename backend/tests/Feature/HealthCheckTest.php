<?php

declare(strict_types=1);

// The app has no web UI; /up is the framework's liveness probe.
it('responds successfully on the health check endpoint', function (): void {
    $this->get('/up')->assertOk();
});
