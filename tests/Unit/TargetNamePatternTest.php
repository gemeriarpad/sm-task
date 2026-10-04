<?php

namespace Tests\Unit;

use App\Http\Requests\TargetRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TargetNamePatternTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function names(): array
    {
        return [
            'lowercase login' => ['laravel', true],
            'mixed case login' => ['TaylorOtwell', true],
            'digits' => ['pyr0hu', true],
            'single character' => ['a', true],
            'inner hyphen' => ['laravel-shift', true],
            'legacy double hyphen' => ['some--user', true],
            'leading hyphen' => ['-laravel', false],
            'trailing hyphen' => ['laravel-', false],
            'underscore' => ['lara_vel', false],
            'dot' => ['lara.vel', false],
            'space' => ['two words', false],
            'url' => ['https://github.com/laravel', false],
            'at sign' => ['@laravel', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('names')]
    public function test_github_login_format(string $name, bool $valid)
    {
        $this->assertSame($valid, preg_match(TargetRequest::NAME_PATTERN, $name) === 1);
    }
}
