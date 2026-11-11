<?php

namespace App\Services\Nonogram\Rulesets;

use InvalidArgumentException;

/** Resolves only implemented mode/version pairs and rejects everything else. */
final class RulesetRegistry
{
    /** @var array<string, PuzzleRuleset> */
    private readonly array $rulesets;

    public function __construct(ClassicRuleset $classic, ColorRuleset $color)
    {
        $this->rulesets = [
            $this->key($classic->id(), $classic->version()) => $classic,
            $this->key($color->id(), $color->version()) => $color,
        ];
    }

    public function default(): PuzzleRuleset
    {
        return $this->resolve(ClassicRuleset::MODE, ClassicRuleset::VERSION);
    }

    public function resolve(string $mode, int $version): PuzzleRuleset
    {
        $ruleset = $this->rulesets[$this->key($mode, $version)] ?? null;
        if ($ruleset === null) {
            throw new InvalidArgumentException('Unsupported puzzle mode or rules version.');
        }

        return $ruleset;
    }

    public function supports(string $mode, int $version): bool
    {
        return isset($this->rulesets[$this->key($mode, $version)]);
    }

    /** @return list<array{id: string, version: int}> */
    public function supported(): array
    {
        return array_values(array_map(
            fn (PuzzleRuleset $ruleset): array => ['id' => $ruleset->id(), 'version' => $ruleset->version()],
            $this->rulesets,
        ));
    }

    private function key(string $mode, int $version): string
    {
        return $mode.':'.$version;
    }
}
