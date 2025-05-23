<?php

namespace Helix\Core\Security;

class JwtAuthenticator implements Authenticator
{
    public function authenticate(Request $request): User
    {
        // JWT verification logic
    }
}
