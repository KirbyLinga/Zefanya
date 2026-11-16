<?php

namespace App\Services\Buyer;

use App\Exceptions\VisualSearchUnavailable;
use Illuminate\Filesystem\Filesystem;
use PDO;
use PDOException;

/** Derived, disposable cache only. This connection never targets the application database. */
class VisualCatalogIndex
{
    private ?PDO $connection = null;

    public function __construct(private Filesystem $files) {}

    public function path(): string
    {
        return config('visual-search.index_path').'.sqlite';
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function database(bool $create = false): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if (! $create && ! $this->exists()) {
            throw new VisualSearchUnavailable('Image search is being prepared. Please try again later.');
        }
        try {
            $this->files->ensureDirectoryExists(dirname($this->path()));
            $db = new PDO('sqlite:'.$this->path(), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA busy_timeout=1000');
            if ($create) {
                $db->exec('PRAGMA journal_mode=WAL');
                $db->exec('CREATE TABLE IF NOT EXISTS features (hash TEXT NOT NULL, version TEXT NOT NULL, data TEXT NOT NULL, PRIMARY KEY(hash, version))');
                $db->exec('CREATE TABLE IF NOT EXISTS images (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, path TEXT NOT NULL, fingerprint TEXT NOT NULL, hash TEXT NOT NULL, version TEXT NOT NULL)');
                $db->exec('CREATE INDEX IF NOT EXISTS image_fingerprint ON images(fingerprint)');
                $db->exec('CREATE INDEX IF NOT EXISTS image_hash ON images(hash,version)');
                $db->exec('CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
                $db->exec('CREATE TABLE IF NOT EXISTS semantic_features (hash TEXT NOT NULL, version TEXT NOT NULL, data TEXT NOT NULL, PRIMARY KEY(hash, version))');
            }

            return $this->connection = $db;
        } catch (PDOException) {
            throw new VisualSearchUnavailable('The image index is unavailable. Please try again later.');
        }
    }

    public function row(int $id): ?array
    {
        try {
            $statement = $this->database()->prepare('SELECT i.*, f.data FROM images i JOIN features f ON f.hash=i.hash AND f.version=i.version WHERE i.id=?');
            $statement->execute([$id]);

            return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException) {
            throw new VisualSearchUnavailable('The image index is unavailable. Please try again later.');
        }
    }

    public function descriptor(string $hash): ?string
    {
        $statement = $this->database()->prepare('SELECT data FROM features WHERE hash=? AND version=?');
        $statement->execute([$hash, VisualImageFeatures::VERSION]);

        return $statement->fetchColumn() ?: null;
    }

    public function semantic(string $hash): ?array
    {
        try {
            $statement = $this->database()->prepare('SELECT data FROM semantic_features WHERE hash=? AND version=?');
            $statement->execute([$hash, config('visual-search.semantic.version')]);
            $data = $statement->fetchColumn();

            $decoded = $data ? json_decode($data, true, flags: JSON_THROW_ON_ERROR) : null;

            return is_array($decoded) ? $decoded : null;
        } catch (PDOException|\JsonException) {
            return null;
        }
    }

    public function putSemantic(string $hash, array $descriptor): void
    {
        $statement = $this->database()->prepare('INSERT OR REPLACE INTO semantic_features(hash,version,data) VALUES(?,?,?)');
        $statement->execute([$hash, config('visual-search.semantic.version'), json_encode($descriptor, JSON_THROW_ON_ERROR)]);
    }

    public function hashForFingerprint(string $fingerprint): ?string
    {
        $statement = $this->database()->prepare('SELECT hash FROM images WHERE fingerprint=? AND version=? LIMIT 1');
        $statement->execute([$fingerprint, VisualImageFeatures::VERSION]);

        return $statement->fetchColumn() ?: null;
    }

    public function put(int $id, int $productId, string $path, string $fingerprint, string $hash, string $features): void
    {
        $db = $this->database();
        $db->prepare('INSERT OR REPLACE INTO features(hash,version,data) VALUES(?,?,?)')->execute([$hash, VisualImageFeatures::VERSION, $features]);
        $db->prepare('INSERT OR REPLACE INTO images(id,product_id,path,fingerprint,hash,version) VALUES(?,?,?,?,?,?)')
            ->execute([$id, $productId, $path, $fingerprint, $hash, VisualImageFeatures::VERSION]);
    }

    public function forget(int $id): void
    {
        $this->database()->prepare('DELETE FROM images WHERE id=?')->execute([$id]);
    }

    public function cursor(): int
    {
        return (int) $this->database()->query("SELECT value FROM metadata WHERE key='cursor'")->fetchColumn();
    }

    public function setCursor(int $id): void
    {
        $this->database()->prepare("INSERT OR REPLACE INTO metadata(key,value) VALUES('cursor',?)")->execute([(string) $id]);
    }

    public function mark(int $id): void
    {
        $directory = $this->path().'.pending';
        $this->withPendingLock(fn () => $this->files->replace($directory.'/'.$id, bin2hex(random_bytes(16))));
    }

    /** Directory iteration is bounded: event storms do not load the entire spool into PHP. */
    public function pending(int $limit): array
    {
        $directory = $this->path().'.pending';
        if (! is_dir($directory)) {
            return [];
        }
        $pending = [];
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isFile() && ctype_digit($file->getFilename())) {
                $pending[(int) $file->getFilename()] = $this->files->get($file->getPathname());
                if (count($pending) >= $limit) {
                    break;
                }
            }
        }

        return $pending;
    }

    public function acknowledge(int $id, string $token): void
    {
        $path = $this->path().'.pending/'.$id;
        // A later event must survive an earlier build's acknowledgement.
        $this->withPendingLock(function () use ($path, $token): void {
            if (is_file($path) && $this->files->get($path) === $token) {
                $this->files->delete($path);
            }
        });
    }

    private function withPendingLock(callable $callback): void
    {
        $directory = $this->path().'.pending';
        $this->files->ensureDirectoryExists($directory);
        $lock = fopen($directory.'/.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new VisualSearchUnavailable('Unable to schedule image indexing.');
        }
        try {
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
