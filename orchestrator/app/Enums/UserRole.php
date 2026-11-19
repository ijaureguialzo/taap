<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';
    case Admin = 'admin';

    public static function fromString(?string $value): self
    {
        return match ($value) {
            'admin' => self::Admin,
            default => self::User,
        };
    }
}
