<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canManageMembers(): bool
    {
        return match ($this) {
            self::Owner, self::Admin => true,
            default => false,
        };
    }

    public function canUpdateWorkspace(): bool
    {
        return match ($this) {
            self::Owner, self::Admin, self::Editor => true,
            default => false,
        };
    }

    public function canDeleteWorkspace(): bool
    {
        return $this === self::Owner;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Editor => 2,
            self::Viewer => 1,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
