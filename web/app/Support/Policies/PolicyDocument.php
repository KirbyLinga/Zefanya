<?php

namespace App\Support\Policies;

/** One policy document at one version. Immutable. */
final class PolicyDocument
{
    /**
     * @param  list<array{id: string, label: string}>  $sections
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $audience,
        public readonly bool $public,
        public readonly string $version,
        public readonly ?string $effectiveDate,
        public readonly string $markdown,
        public readonly string $html,
        public readonly array $sections,
        public readonly string $sha256,
        public readonly bool $hasUnconfirmedFields,
    ) {}

    /** Wire format shared with the mobile app. Only built for public documents. */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'version' => $this->version,
            'effective_date' => $this->effectiveDate,
            'sha256' => $this->sha256,
            'has_unconfirmed_fields' => $this->hasUnconfirmedFields,
            'sections' => $this->sections,
            'markdown' => $this->markdown,
        ];
    }
}
