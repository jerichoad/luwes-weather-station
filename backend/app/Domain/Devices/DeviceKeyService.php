<?php

namespace App\Domain\Devices;

use Illuminate\Support\Str;

final class DeviceKeyService
{
    public function __construct(private readonly string $pepper) {}

    public function generate(): string
    {
        return 'wsk_'.Str::random(40);
    }

    public function hash(string $key): string
    {
        return hash_hmac('sha256', $key, $this->pepper);
    }

    public function prefix(string $key): string
    {
        return substr($key, 0, 12);
    }

    public function matches(string $key, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($key));
    }
}
