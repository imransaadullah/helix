<?php

namespace Helix\Core\Security;

interface Authenticator
{
    public function authenticate(Request $request): User;
}
