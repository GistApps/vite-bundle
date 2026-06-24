<?php

namespace Pentatrion\ViteBundle\Service;

use Pentatrion\ViteBundle\Exception\EntrypointsFileNotFoundException;
use Pentatrion\ViteBundle\Exception\VersionMismatchException;
use Pentatrion\ViteBundle\PentatrionViteBundle;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-type EntryPoint array{
 *  assets?: array<string>,
 *  js?: array<string>,
 *  css?: array<string>,
 *  preload?: array<string>,
 *  dynamic?: array<string>,
 *  legacy: false|string,
 * }
 * @phpstan-type FileMetadatas array{
 *  hash: string|null
 * }
 * @phpstan-type EntryPointsFile array{
 *  base: string,
 *  entryPoints: array<string, EntryPoint>,
 *  legacy: bool,
 *  metadatas: array<string, FileMetadatas>,
 *  version: array{0: string, 1: int, 2: int, 3: int},
 *  viteServer: string|null
 * }
 * @phpstan-type ManifestEntry array{
 *  file: string,
 *  src?: string,
 *  isDynamicEntry?: bool,
 *  isEntry?: bool,
 *  imports?: array<string>,
 *  css?: array<string>
 * }
 * @phpstan-type ManifestFile array<string, ManifestEntry>
 */
class FileAccessor
{
    public const ENTRYPOINTS = 'entrypoints';
    public const MANIFEST = 'manifest';

    public const FILES = [
        self::ENTRYPOINTS => 'entrypoints.json',
        self::MANIFEST => 'manifest.json',
    ];

    /** @var array<string, array<string, EntryPointsFile|ManifestFile>> */
    private array $content;

    /** @param array<string, array<mixed>> $configs */
    public function __construct(
        private string $publicPath,
        private array $configs,
        private LoggerInterface $logger,
        private ?CacheItemPoolInterface $cache = null,
    ) {
    }

    public function hasFile(string $configName, string $fileType): bool
    {
        if (!empty($this->configs[$configName]['manifest_prefix_url'])) {
            // For remote URLs we assume the file is present; getData() will throw on failure.
            return true;
        }

        $basePath = $this->publicPath.$this->configs[$configName]['base'];

        return file_exists($basePath.'.vite/'.self::FILES[$fileType]) || file_exists($basePath.self::FILES[$fileType]);
    }

    /**
     * @param key-of<FileAccessor::FILES> $fileType
     *
     * @phpstan-return ($fileType is 'entrypoints' ? EntryPointsFile : ManifestFile)
     */
    public function getData(string $configName, string $fileType): array
    {
        $cacheItem = null;
        
        $this->logger->debug('ViteBundle FileAccessor: retrieving data for config "{config}" and file type "{fileType}"', [
            'config' => $configName,
            'fileType' => $fileType,
        ]);
        if (!isset($this->content[$configName][$fileType])) {

            $this->logger->debug('ViteBundle FileAccessor: "$this->content[$configName][$fileType]" is not set', [
                'key' => "$configName.$fileType",
            ]);

            if ($this->cache) {
                $this->logger->debug('ViteBundle FileAccessor: cache is enabled, checking for key "{key}"', [
                    'key' => "$configName.$fileType",
                ]);
                $cacheKey = "$configName.$fileType";
                $cacheItem = $this->cache->getItem($cacheKey);
                
                if ($cacheItem->isHit()) {
                    /** @var EntryPointsFile|ManifestFile $data */
                    $data = $cacheItem->get();
                    $firstEntry = is_array($data) ? array_slice($data, 0, 1, true) : [];
                    $this->logger->debug('ViteBundle FileAccessor: cache HIT for key "{key}" (first entry: {first})', [
                        'key' => $cacheKey,
                        'first' => json_encode($firstEntry, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                    ]);
                    $this->content[$configName][$fileType] = $data;
                } else {
                    $this->logger->debug('ViteBundle FileAccessor: cache MISS for key "{key}"', [
                        'key' => $cacheKey,
                    ]);
                }
            } else {
                $this->logger->debug('ViteBundle FileAccessor: cache is disabled');
            }

            if (!isset($this->content[$configName][$fileType])) {
                $manifestPrefixUrl = $this->configs[$configName]['manifest_prefix_url'] ?? null;
                $this->logger->debug('ViteBundle FileAccessor: "$this->content[$configName][$fileType]" is still not set, retrieving from file', [
                    'key' => "$configName.$fileType",
                ]);
                if (!empty($manifestPrefixUrl)) {
                    $filePath = rtrim($manifestPrefixUrl, '/').'/'.self::FILES[$fileType];
                    $context = stream_context_create([
                        'http' => ['timeout' => 10],
                        'https' => ['timeout' => 10],
                    ]);
                    $result = @file_get_contents($filePath, false, $context);
                    
                    if (false === $result) {
                        $this->logger->error('ViteBundle FileAccessor: "$this->content[$configName][$fileType]" could not be retrieved from file', [
                            'key' => "$configName.$fileType",
                            'filePath' => $filePath,
                        ]);
                        throw new EntrypointsFileNotFoundException("$fileType not found at $filePath. Check your manifest_prefix_url configuration.");
                    }
                    /** @var EntryPointsFile|ManifestFile $content */
                    $content = json_decode($result, true, 512, \JSON_THROW_ON_ERROR);
                } else {
                    $filePath = $this->publicPath.$this->configs[$configName]['base'].self::FILES[$fileType];
                    $basePath = $this->publicPath.$this->configs[$configName]['base'];

                    $this->logger->debug('ViteBundle FileAccessor: "$this->content[$configName][$fileType]" is still not set, retrieving from local file', [
                        'key' => "$configName.$fileType",
                        'filePath' => $filePath,
                    ]);
                    if (file_exists($basePath.'.vite/'.self::FILES[$fileType])) {
                        $filePath = $basePath.'.vite/'.self::FILES[$fileType];
                    } elseif (file_exists($basePath.self::FILES[$fileType])) {
                        $filePath = $basePath.self::FILES[$fileType];
                    } else {
                        throw new EntrypointsFileNotFoundException("$fileType not found at $basePath. Did you forget configure your `build_directory` in pentatrion_vite.yml");
                    }

                    /** @var EntryPointsFile|ManifestFile $content */
                    $content = json_decode((string) file_get_contents($filePath), true, flags: \JSON_THROW_ON_ERROR);
                }

                if (self::ENTRYPOINTS === $fileType) {
                    /** @var EntryPointsFile $content */
                    $pluginVersion = $content['version'];
                    // VERSION[1] => Major version number
                    
                    if (PentatrionViteBundle::VERSION[1] !== $pluginVersion[1]) {
                        throw new VersionMismatchException('your vite-plugin-symfony is outdated, run : npm install vite-plugin-symfony@^'.PentatrionViteBundle::VERSION[1]);
                    }
                }

                if ($this->cache && null !== $cacheItem) {
                    $this->cache->save($cacheItem->set($content));
                    $this->logger->debug('ViteBundle FileAccessor: cache item saved for key "{key}"', [
                        'key' => "$configName.$fileType",
                    ]);
                }

                $this->logger->debug('ViteBundle FileAccessor: "$this->content[$configName][$fileType]" is now set', [
                    'key' => "$configName.$fileType",
                ]);

                $this->content[$configName][$fileType] = $content;
            }
        } else {
            $this->logger->debug('ViteBundle FileAccessor: cache HIT for key "{key}"', [
                'key' => "$configName.$fileType",
                'value' => json_encode(array_slice($this->content[$configName][$fileType], 0, 1, true), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $this->content[$configName][$fileType];
    }
}
