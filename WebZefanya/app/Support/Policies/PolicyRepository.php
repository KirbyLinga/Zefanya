<?php

namespace App\Support\Policies;

use Illuminate\Support\Str;

/**
 * Reads the approved policy Markdown from docs/Policy.
 *
 * Operator identity, contacts and effective dates are placeholders in the
 * source until verified. They are never invented here: an unconfirmed field is
 * flagged on the page and the effective date is hidden.
 */
class PolicyRepository
{
    private const TBC_OPEN = '@@tbc@@';

    private const TBC_CLOSE = '@@/tbc@@';

    /** @var array<string, PolicyDocument> */
    private array $cache = [];

    /** @return list<string> */
    public function publicSlugs(): array
    {
        return array_keys(array_filter(config('policies.documents'), fn (array $d) => $d['public']));
    }

    /** @return list<PolicyDocument> Public documents only. */
    public function allPublic(): array
    {
        return array_map(fn (string $slug) => $this->find($slug), $this->publicSlugs());
    }

    /** A public document, or null when unknown or internal. */
    public function findPublic(string $slug): ?PolicyDocument
    {
        $config = config("policies.documents.{$slug}");

        return $config && $config['public'] ? $this->find($slug) : null;
    }

    /** Any configured document, including internal ones. Callers must authorize. */
    public function find(string $slug): ?PolicyDocument
    {
        $config = config("policies.documents.{$slug}");
        if (! $config) {
            return null;
        }

        return $this->cache[$slug] ??= $this->load($slug, $config);
    }

    /** @param  array{title: string, file: string, public: bool, audience: string}  $config */
    private function load(string $slug, array $config): PolicyDocument
    {
        $path = rtrim(config('policies.directory'), '/\\').DIRECTORY_SEPARATOR.$config['file'];
        if (! is_file($path)) {
            throw new \RuntimeException("Policy source missing: {$config['file']}");
        }

        $raw = str_replace("\r\n", "\n", (string) file_get_contents($path));

        $version = preg_match('/\*\*(?:Policy )?[Vv]ersion:\*\*\s*([0-9][0-9.]*)/', $raw, $m) ? $m[1] : 'unversioned';
        $effective = preg_match('/\*\*Effective:\*\*\s*([^|\n]+?)\s*(?:\||\n|$)/', $raw, $m) ? trim($m[1]) : null;
        $effective = ($effective === null || str_contains($effective, '[INSERT')) ? null : $effective;

        // Public text: drop the internal publication note and the h1 (the page supplies the title).
        $body = preg_replace('/\A(?:>[^\n]*\n)+\s*/', '', $raw);
        $body = preg_replace('/\A#\s[^\n]*\n+/', '', $body);
        $hasUnconfirmed = str_contains($body, '[INSERT');

        $marked = preg_replace('/\[INSERT[^\]]*\]/', self::TBC_OPEN.'to be confirmed before publication'.self::TBC_CLOSE, $body);
        $html = Str::markdown($marked, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = str_replace(
            [self::TBC_OPEN, self::TBC_CLOSE],
            ['<span class="policy-tbc" data-policy-tbc>[', ']</span>'],
            $html,
        );

        [$html, $sections] = $this->anchorHeadings($html);

        return new PolicyDocument(
            slug: $slug,
            title: $config['title'],
            audience: $config['audience'],
            public: $config['public'],
            version: $version,
            effectiveDate: $effective,
            markdown: preg_replace('/\[INSERT[^\]]*\]/', '[to be confirmed before publication]', $body),
            html: $html,
            sections: $sections,
            sha256: hash('sha256', $raw),
            hasUnconfirmedFields: $hasUnconfirmed,
        );
    }

    /**
     * Give each h2 a stable id and collect a table of contents.
     *
     * @return array{0: string, 1: list<array{id: string, label: string}>}
     */
    private function anchorHeadings(string $html): array
    {
        $sections = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/s', function (array $m) use (&$sections): string {
            $label = trim(html_entity_decode(strip_tags($m[1])));
            $id = Str::slug(preg_replace('/^\d+\.\s*/', '', $label)) ?: 'section-'.(count($sections) + 1);
            $sections[] = ['id' => $id, 'label' => $label];

            return '<h2 id="'.e($id).'">'.$m[1].'</h2>';
        }, $html);

        return [$html, $sections];
    }
}
