<?php namespace Winter\Octane\Classes;

use Exception;
use RuntimeException;
use Winter\Storm\Halcyon\Datasource\FileDatasource;

/**
 * Reads theme templates in a worker without repeating work that only changes when a file does.
 *
 * Rendering a page looks up a theme override for every component partial, and most of those lookups
 * find nothing. FileDatasource resolves the path segment by segment for each lookup and throws an
 * exception for each missing file. A process that ends after the request has nowhere to keep the
 * result; a worker does.
 *
 * This datasource remembers each resolved path and each file's contents, and stats the file on every
 * read, so an edit, a new override or a deletion is seen by the next read in every worker, as it is
 * under PHP-FPM. A template reached through a symlink inside the theme is resolved on every read, as
 * FileDatasource does, so it cannot lead outside the directory the datasource first resolved. Writes
 * and list queries use FileDatasource unchanged. Template source is the same for every user, so
 * nothing kept here can carry one request's state into the next.
 *
 * The single-template cache keys it makes are recorded, so the reset can keep the parsed templates
 * that Halcyon caches under them in memory. Halcyon compares a cached template's modification time
 * with the file's before it uses it, through lastModified() here, which stats the file.
 *
 * A symlink on the theme directory or a directory above it that is repointed under a running worker
 * needs a worker reload, as Octane asks for after a deployment, because a remembered path still names
 * the old target.
 */
class WorkerFileDatasource extends FileDatasource
{
    /**
     * @var int File names can come from request data, so the remembered paths need a limit.
     */
    protected const MAX_READ_PATHS = 10000;

    /**
     * @var int The most template source one datasource holds in memory, in bytes. Each theme has its
     * own datasource in each worker.
     */
    protected const MAX_CONTENT_BYTES = 67108864;

    /**
     * @var int A file changed this recently could change again without its timestamps moving, because
     * stat() reports them in whole seconds, so its contents are not kept yet.
     */
    protected const SETTLE_SECONDS = 2;

    /**
     * @var int Template names can come from request data, so the recorded cache keys need a limit.
     */
    protected const MAX_RECORD_CACHE_KEYS = 10000;

    /**
     * @var array<string, string> Resolved paths for reads, by directory, file name and extension.
     */
    protected array $readPaths = [];

    /**
     * @var array<string, array{signature: string, content: string}> File contents by resolved path.
     */
    protected array $contents = [];

    /**
     * @var int The size of everything in $contents, in bytes.
     */
    protected int $contentBytes = 0;

    /**
     * @var array<string, string|false> The base path of the datasource that made each single-template
     * cache key, or false once datasources over different directories made the same one. Halcyon does
     * not put the datasource in its cache keys, so the same template name in two themes shares a key.
     */
    protected static array $recordCacheKeys = [];

    /**
     * Make a worker datasource that reads the same files as an existing one.
     *
     * @param \Winter\Storm\Halcyon\Datasource\FileDatasource $source
     * @return self
     */
    public static function fromFileDatasource(FileDatasource $source): self
    {
        $datasource = new self($source->basePath, $source->files);
        $datasource->maxDepth = $source->maxDepth;

        return $datasource;
    }

    /**
     * @inheritDoc
     *
     * @return array{fileName: string, content: string, mtime: int}|null
     */
    public function selectOne(string $dirName, string $fileName, string $extension): ?array
    {
        try {
            $path = $this->readPath($dirName, $fileName, $extension);
            $stat = $this->statFile($path);

            if ($stat === null) {
                $this->forgetContent($path);

                return null;
            }

            return [
                'fileName' => $fileName . '.' . $extension,
                'content'  => $this->readContent($path, $stat),
                'mtime'    => (int) $stat['mtime'],
            ];
        }
        catch (Exception $ex) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function makeCacheKey(string $name = ''): string
    {
        $key = parent::makeCacheKey($name);

        // Builder::generateCacheKey() starts the name with a single-template query's serialized name and extension.
        if (str_starts_with($name, 'a:2:{')) {
            if (count(static::$recordCacheKeys) >= static::MAX_RECORD_CACHE_KEYS) {
                static::$recordCacheKeys = [];
            }

            $madeBy = static::$recordCacheKeys[$key] ?? $this->basePath;
            static::$recordCacheKeys[$key] = $madeBy === $this->basePath ? $madeBy : false;
        }

        return $key;
    }

    /**
     * Whether a Halcyon cache key belongs to a single-template query made through a datasource of this
     * class, and only one theme directory's.
     *
     * @param string $key The cache key, which Halcyon makes from the object directory and makeCacheKey().
     * @return bool
     */
    public static function madeRecordCacheKey(string $key): bool
    {
        return strlen($key) > 8 && is_string(static::$recordCacheKeys[substr($key, -8)] ?? null);
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $dirName, string $fileName, string $extension): ?int
    {
        try {
            $stat = $this->statFile($this->readPath($dirName, $fileName, $extension));

            return $stat === null ? null : (int) $stat['mtime'];
        }
        catch (Exception $ex) {
            return null;
        }
    }

    /**
     * The resolved path of a template. A path that resolves outside the datasource throws in
     * makeFilePath(), and is not remembered. PHP remembers the last stat result for the life of the
     * process, so it has to forget that one before this looks at the file.
     *
     * Each segment below the theme is checked for a symlink on every read, which costs one lstat per
     * segment, far less than resolving the whole path. A path that passes through one is not remembered,
     * so makeFilePath() checks it against the theme directory on every read.
     *
     * @param string $dirName
     * @param string $fileName
     * @param string $extension
     * @return string
     */
    protected function readPath(string $dirName, string $fileName, string $extension): string
    {
        clearstatcache();

        $key = $dirName . "\0" . $fileName . "\0" . $extension;

        if ($this->passesThroughSymlink($dirName . '/' . $fileName . '.' . $extension)) {
            unset($this->readPaths[$key]);

            return $this->makeFilePath($dirName, $fileName, $extension);
        }

        if (isset($this->readPaths[$key])) {
            return $this->readPaths[$key];
        }

        $path = $this->makeFilePath($dirName, $fileName, $extension);

        // A name this long cannot be a file, so it is not worth the memory.
        if (strlen($key) > PHP_MAXPATHLEN) {
            return $path;
        }

        if (count($this->readPaths) >= static::MAX_READ_PATHS) {
            $this->readPaths = [];
        }

        return $this->readPaths[$key] = $path;
    }

    /**
     * Whether any segment of a path below the theme directory is a symlink.
     *
     * @param string $relativePath
     * @return bool
     */
    protected function passesThroughSymlink(string $relativePath): bool
    {
        $path = $this->basePath;

        foreach (explode('/', $relativePath) as $segment) {
            $path .= '/' . $segment;

            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stat a file, or return null when it is not a regular file.
     *
     * @param string $path
     * @return array<int|string, int>|null
     */
    protected function statFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $stat = @stat($path);

        return $stat === false ? null : $stat;
    }

    /**
     * A file's contents, read again only when its stat result differs from the one they were kept with.
     * The stat comes before the read, so a file written in between is read again on the next lookup.
     *
     * @param string $path
     * @param array<int|string, int> $stat
     * @return string
     */
    protected function readContent(string $path, array $stat): string
    {
        $signature = implode(':', [$stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino']]);

        if (isset($this->contents[$path]) && $this->contents[$path]['signature'] === $signature) {
            return $this->contents[$path]['content'];
        }

        $this->forgetContent($path);

        $content = $this->files->get($path);

        // With warnings masked, a failed read returns false rather than throwing.
        if (!is_string($content)) {
            throw new RuntimeException("Could not read {$path}.");
        }

        if (time() - max($stat['mtime'], $stat['ctime']) >= static::SETTLE_SECONDS) {
            $this->keepContent($path, $signature, $content);
        }

        return $content;
    }

    /**
     * Keep a file's contents, starting again when they would exceed the limit.
     *
     * @param string $path
     * @param string $signature
     * @param string $content
     * @return void
     */
    protected function keepContent(string $path, string $signature, string $content): void
    {
        $this->forgetContent($path);

        $size = strlen($content);

        if ($size > static::MAX_CONTENT_BYTES) {
            return;
        }

        if ($this->contentBytes + $size > static::MAX_CONTENT_BYTES) {
            $this->contents = [];
            $this->contentBytes = 0;
        }

        $this->contents[$path] = ['signature' => $signature, 'content' => $content];
        $this->contentBytes += $size;
    }

    /**
     * Forget a file's kept contents.
     *
     * @param string $path
     * @return void
     */
    protected function forgetContent(string $path): void
    {
        if (isset($this->contents[$path])) {
            $this->contentBytes -= strlen($this->contents[$path]['content']);
            unset($this->contents[$path]);
        }
    }
}
