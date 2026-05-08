<?php

declare(strict_types=1);

/**
 * Phase 3 fixture — exercises the recursive capture serialiser on:
 *   primitives, strings (truncated), arrays (assoc + list, truncated),
 *   typed objects, readonly properties, enums (backed + pure),
 *   __get-having objects (lazy detection), circular references.
 */

enum Status: string
{
    case Active   = 'A';
    case Inactive = 'I';
    case Pending  = 'P';
}

enum Tier
{
    case Free;
    case Pro;
    case Enterprise;
}

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public Status $status,
        public Tier $tier,
        /** @var array<int, string> */
        public array $roles,
        public ?User $manager = null,
    ) {}
}

final class LazyProxy
{
    public function __get(string $name): mixed
    {
        // periscope must NOT trigger this when capturing the object
        throw new RuntimeException("__get should not be invoked by periscope");
    }
}

function describe(User $user): string
{
    return $user->email . ' [' . $user->status->value . ']';
}

function take_array(array $data): int
{
    return count($data);
}

function take_lazy(LazyProxy $p): string
{
    return 'inspected without firing __get';
}

$root = new User(
    id: 1,
    email: 'thami@example.com',
    status: Status::Active,
    tier: Tier::Pro,
    roles: ['admin', 'editor'],
);
$child = new User(
    id: 2,
    email: 'jane@example.com',
    status: Status::Pending,
    tier: Tier::Free,
    roles: ['viewer'],
    manager: $root,
);

// Cyclic reference — root.manager = child; child.manager = root
$root->manager = $child;

echo describe($root), "\n";
echo describe($child), "\n";
echo take_array(['name' => 'thami', 'age' => 30, 'tags' => ['php', 'rust']]), "\n";
echo take_lazy(new LazyProxy()), "\n";
