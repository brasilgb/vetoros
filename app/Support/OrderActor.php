<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

/**
 * Quem originou um evento da OS. O cliente na área pública nunca é tratado como usuário interno.
 */
final class OrderActor
{
    public const USER = 'user';

    public const CUSTOMER = 'customer';

    public const SYSTEM = 'system';

    private function __construct(
        public readonly string $type,
        public readonly ?int $userId = null,
    ) {}

    public static function user(User|int $user): self
    {
        $id = $user instanceof User ? (int) $user->id : $user;

        if ($id <= 0) {
            throw new InvalidArgumentException('Ator do tipo usuário exige um id válido.');
        }

        return new self(self::USER, $id);
    }

    /**
     * Usuário autenticado quando houver; caso contrário, o sistema.
     */
    public static function userOrSystem(User|int|null $user): self
    {
        return $user ? self::user($user) : self::system();
    }

    public static function customer(): self
    {
        return new self(self::CUSTOMER);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM);
    }
}
