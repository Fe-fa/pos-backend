<?php

namespace App\Support;

class ChequeBankDirectory
{
    public static function all(): array
    {
        return [
            ['code' => 'KCB', 'name' => 'KCB Bank Kenya'],
            ['code' => 'ABSA', 'name' => 'Absa Bank Kenya'],
            ['code' => 'EQUITY', 'name' => 'Equity Bank Kenya'],
            ['code' => 'COOP', 'name' => 'Co-operative Bank of Kenya'],
            ['code' => 'NCBA', 'name' => 'NCBA Bank Kenya'],
            ['code' => 'STANBIC', 'name' => 'Stanbic Bank Kenya'],
            ['code' => 'IM', 'name' => 'I&M Bank Kenya'],
            ['code' => 'DTB', 'name' => 'Diamond Trust Bank Kenya'],
            ['code' => 'FAMILY', 'name' => 'Family Bank Kenya'],
        ];
    }

    public static function resolve(?string $value): ?array
    {
        $needle = strtolower(trim((string) $value));
        if ($needle === '') {
            return null;
        }

        foreach (self::all() as $bank) {
            $label = strtolower($bank['code'] . ' - ' . $bank['name']);
            if (
                strtolower($bank['code']) === $needle
                || strtolower($bank['name']) === $needle
                || $label === $needle
                || str_contains($label, $needle)
            ) {
                return $bank;
            }
        }

        return null;
    }
}
