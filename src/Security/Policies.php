<?php

namespace Helix\Core\Security;

final class PolicyManager
{
    private array $policies = [];
    
    public function check(User $user, string $action, mixed $subject): bool
    {
        foreach ($this->policies as $policy) {
            if ($policy->supports($subject) && !$policy->check($user, $action, $subject)) {
                return false;
            }
        }
        return true;
    }

    public function addPolicy(PolicyInterface $policy): void
    {
        $this->policies[] = $policy;
    }

    public function removePolicy(PolicyInterface $policy): void
    {
        $this->policies = array_filter($this->policies, fn($p) => $p !== $policy);
    }

    public function getPolicies(): array
    {
        return $this->policies;
    }
}
