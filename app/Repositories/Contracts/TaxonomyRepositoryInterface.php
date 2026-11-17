<?php

namespace App\Repositories\Contracts;

interface TaxonomyRepositoryInterface
{
    /** sub_class => [financial_statement, main_class, normal_side] */
    public function map(): array;

    public function subClasses(): array;

    public function find(string $subClass): ?array;

    public function familyOf(string $mainClass): ?string;

    /** Every main_class name that has a sub class, in table order. */
    public function mainClasses(): array;

    /** 'debit' | 'credit' — the side a normal balance of this sub_class sits on. */
    public function normalSide(string $subClass): ?string;

    /** The current name of a sub class from its current or any former name (taxonomy page renames); null when unknown. */
    public function resolveSub(?string $name): ?string;

    /** The current name of a main class from its current or any former name; null when unknown. */
    public function resolveMain(?string $name): ?string;

    /** Categories renamed on the taxonomy page: ['main' => [config name => current], 'sub' => [...]]. */
    public function renamedOrigins(): array;
}
