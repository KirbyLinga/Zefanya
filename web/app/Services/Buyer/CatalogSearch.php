<?php

namespace App\Services\Buyer;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Search rules shared by the navbar's destination (/buyer/products), the search
 * page (/buyer/search) and the search suggestions, so they cannot drift apart.
 */
final class CatalogSearch
{
    private const MAX_LENGTH = 100;

    private const MAX_WORDS = 8;

    private const VOCABULARY_KEY = 'catalog-search-vocabulary';

    private const VOCABULARY_SECONDS = 600;

    private const VOCABULARY_NAMES = 5000;

    /** Words this short have too many near neighbours to correct safely. */
    private const MIN_CORRECTABLE_LENGTH = 3;

    /** Query-string input to a clean term: never an array, single spaces, trimmed. */
    public static function term(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        return trim(mb_substr((string) preg_replace('/\s+/u', ' ', $raw), 0, self::MAX_LENGTH));
    }

    /**
     * Every word must appear in the name or description, in any order and any
     * case. % and _ are searched for literally, not treated as wildcards.
     */
    public static function apply(Builder $query, string $term): Builder
    {
        foreach (self::words($term) as $word) {
            $like = '%'.self::escapeLike($word).'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$like]));
        }

        return $query;
    }

    /** A whole-phrase LIKE pattern, for labels and store names. */
    public static function phrasePattern(string $term): string
    {
        return '%'.self::escapeLike(mb_strtolower($term)).'%';
    }

    /**
     * Sort out min_price and max_price. Anything invalid drops the whole price
     * filter, so results stay honest and the message says why.
     *
     * @param  array<string, mixed>  $input
     * @return array{filters: array<string, string>, error: string|null}
     */
    public static function priceFilters(array $input): array
    {
        $filters = [];

        foreach (['min_price', 'max_price'] as $key) {
            $value = $input[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                return ['filters' => [], 'error' => 'Enter prices as numbers, like 500 or 499.50.'];
            }

            if ((float) $value < 0) {
                return ['filters' => [], 'error' => 'Prices cannot be negative.'];
            }

            $filters[$key] = (string) $value;
        }

        if (isset($filters['min_price'], $filters['max_price']) && (float) $filters['min_price'] > (float) $filters['max_price']) {
            return ['filters' => [], 'error' => 'The minimum price cannot be higher than the maximum price.'];
        }

        return ['filters' => $filters, 'error' => null];
    }

    /**
     * "Did you mean": correct each unknown word to the closest word buyers can
     * actually find. Returns null unless the correction leads to real results,
     * so the link can never be a dead end.
     */
    public static function suggest(string $term): ?string
    {
        $vocabulary = self::vocabulary();
        $corrected = [];

        foreach (self::words($term) as $word) {
            $corrected[] = self::closest($word, $vocabulary);
        }

        $suggestion = implode(' ', $corrected);

        if ($suggestion === '' || $suggestion === mb_strtolower($term)) {
            return null;
        }

        return self::apply(self::visibleProducts(), $suggestion)->exists() ? $suggestion : null;
    }

    /** @return list<string> Lowercase words, capped so a long paste stays a cheap query. */
    private static function words(string $term): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($words, 0, self::MAX_WORDS);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @param list<string> $vocabulary */
    private static function closest(string $word, array $vocabulary): string
    {
        $length = mb_strlen($word);

        if ($length < self::MIN_CORRECTABLE_LENGTH || in_array($word, $vocabulary, true)) {
            return $word;
        }

        $allowed = match (true) {
            $length <= 4 => 1,
            $length <= 8 => 2,
            default => 3,
        };
        $best = $word;
        $bestDistance = $allowed + 1;

        // The vocabulary is sorted, so equally close words always resolve the same way.
        foreach ($vocabulary as $candidate) {
            if (abs(mb_strlen($candidate) - $length) > $allowed) {
                continue;
            }

            $distance = self::isAdjacentSwap($word, $candidate) ? 1 : levenshtein($word, $candidate);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** "slik" for "silk" is one slip of the fingers, though edit distance counts it as two. */
    private static function isAdjacentSwap(string $word, string $candidate): bool
    {
        if (strlen($word) !== strlen($candidate)) {
            return false;
        }

        for ($i = 0; $i < strlen($word) - 1; $i++) {
            $swapped = $word;
            [$swapped[$i], $swapped[$i + 1]] = [$word[$i + 1], $word[$i]];

            if ($swapped === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Words from names buyers can see, so a suggestion never reveals a draft or
     * restricted product. Cached because it only runs on an empty search.
     *
     * @return list<string>
     */
    private static function vocabulary(): array
    {
        return Cache::remember(self::VOCABULARY_KEY, self::VOCABULARY_SECONDS, function (): array {
            return self::visibleProducts()
                ->latest('id')
                ->limit(self::VOCABULARY_NAMES)
                ->pluck('name')
                ->flatMap(fn (string $name) => preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ->filter(fn (string $word) => mb_strlen($word) >= self::MIN_CORRECTABLE_LENGTH)
                ->unique()
                ->sort()
                ->values()
                ->all();
        });
    }

    /** The search page's visibility rule: sold-out listings stay findable by name. */
    private static function visibleProducts(): Builder
    {
        return Product::query()
            ->where('status', 'active')
            ->whereNull('restriction_reason')
            ->whereHas('variants', fn ($variants) => $variants->where('is_available', true))
            ->whereHas('seller', fn ($seller) => $seller->where('status', 'approved'));
    }
}
