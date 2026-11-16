<?php

namespace App\Services\Buyer;

use Illuminate\Support\Facades\Http;

/** Optional bounded local inference. Failure never disables structural retrieval. */
class VisualSemanticRecognition
{
    public function enabled(): bool
    {
        return (bool) config('visual-search.semantic.enabled') && ! app()->runningUnitTests();
    }

    public function describe(string $path): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $endpoint = config('visual-search.semantic.endpoint');
        if (! is_string($endpoint) || ! preg_match('~\Ahttp://127\.0\.0\.1:[0-9]{2,5}\z~D', $endpoint)) {
            return null;
        }
        $tokenPath = config('visual-search.semantic.token_path');
        if (! is_file($tokenPath) || ! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            $response = Http::connectTimeout(0.2)->timeout(config('visual-search.semantic.timeout'))
                ->withoutRedirecting()->withOptions(['stream' => true, 'read_timeout' => 1.5])
                ->withHeaders(['X-Zefanya-Visual-Key' => trim(file_get_contents($tokenPath))])
                ->withBody($stream, 'application/octet-stream')->post($endpoint.'/embed');
            if (! $response->successful()) {
                return null;
            }
            $body = $response->toPsrResponse()->getBody();
            try {
                $json = '';
                while (! $body->eof() && strlen($json) <= 32768) {
                    $chunk = $body->read(min(8192, 32769 - strlen($json)));
                    if ($chunk === '') {
                        break;
                    }
                    $json .= $chunk;
                }
            } finally {
                $body->close();
            }
            if (strlen($json) > 32768) {
                return null;
            }
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            return $this->valid($data) ? $data : null;
        } catch (\Exception) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function valid(mixed $data): bool
    {
        if (! is_array($data) || ($data['version'] ?? null) !== config('visual-search.semantic.version')
            || ! in_array($data['family'] ?? null, ['books', 'clothing', 'footwear', 'bags', 'electronics', 'furniture', 'beauty', 'plants', 'food', 'jewelry', 'toys', 'vehicles', 'animals', 'other'], true)
            || ! is_string($data['label'] ?? null) || strlen($data['label']) > 60
            || ! is_string($data['object'] ?? null) || strlen($data['object']) > 60
            || ! is_numeric($data['score'] ?? null) || ! is_finite((float) $data['score']) || $data['score'] < -1 || $data['score'] > 1
            || ! is_numeric($data['margin'] ?? null) || ! is_finite((float) $data['margin']) || $data['margin'] < 0 || $data['margin'] > 2
            || ! is_array($data['embedding'] ?? null) || ! array_is_list($data['embedding']) || count($data['embedding']) !== 512) {
            return false;
        }
        $norm = 0.0;
        foreach ($data['embedding'] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || abs($value) > 1) {
                return false;
            }
            $norm += $value * $value;
        }

        return abs($norm - 1) < 0.02;
    }

    public function confident(?array $data): bool
    {
        return $data !== null && $this->valid($data)
            && in_array($data['family'], config('visual-search.semantic.supported_families', []), true)
            && $data['score'] >= config('visual-search.semantic.minimum_score')
            && $data['margin'] >= config('visual-search.semantic.minimum_margin');
    }

    public function similarity(array $a, array $b): float
    {
        return min(1.0, max(-1.0, array_sum(array_map(fn ($x, $y) => $x * $y, $a['embedding'], $b['embedding']))));
    }

    public function compatible(array $a, array $b): bool
    {
        if ($a['family'] !== $b['family']) {
            return false;
        }
        if (in_array($a['family'], ['clothing', 'electronics', 'furniture', 'vehicles'], true)) {
            $group = fn ($object) => in_array($object, ['shirt', 'blouse'], true) ? 'tops' : $object;

            return $group($a['object']) === $group($b['object']);
        }

        return true;
    }

    public function label(array $data): string
    {
        return match ($data['object']) {
            'dress' => 'Dresses', 'shirt', 'blouse' => 'Tops', 'jacket' => 'Jackets',
            'trousers' => 'Trousers', 'skirt' => 'Skirts', 'smartphone' => 'Phones',
            'laptop computer' => 'Computers', 'headphones' => 'Headphones', 'camera' => 'Cameras',
            'television' => 'Televisions', 'video game console' => 'Gaming',
            default => $data['label'],
        };
    }
}
