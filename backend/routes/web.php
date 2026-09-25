<?php

declare(strict_types=1);

// This backend is a JSON API only: the frontend is a separate app that talks to it
// over REST. All routes live in routes/api.php; this file stays
// registered (and empty) so Laravel's default web middleware group remains
// available if ever needed, without a UI route to serve.
