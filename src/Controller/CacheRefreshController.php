<?php

namespace Pentatrion\ViteBundle\Controller;

use Pentatrion\ViteBundle\Service\FileAccessor;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheRefreshController
{

    /**
     * @param array<string, array<mixed>> $configs
     */
    public function __construct(
        private array $configs,
        private string $cacheRefreshToken,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function refresh(Request $request): JsonResponse
    {

        $this->logger->info('ViteBundle CacheRefreshController: received cache refresh request from {ip}', [
            'ip' => $request->getClientIp(),
        ]);
        
        if (null === $this->cacheRefreshToken) {
            return new JsonResponse(['error' => 'Cache refresh is not configured. Set cache_refresh_token in pentatrion_vite.yaml.'], Response::HTTP_NOT_FOUND);
        }

        $providedToken = $request->headers->get('Authorization', '');
        if (str_starts_with($providedToken, 'Bearer ')) {
            $providedToken = substr($providedToken, 7);
        }

        if (!hash_equals($this->cacheRefreshToken, $providedToken)) {
            return new JsonResponse(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        if (null === $this->cache) {
            return new JsonResponse(['message' => 'Cache is not enabled; nothing to clear.'], Response::HTTP_OK);
        }

        $deleted = [];
        foreach (array_keys($this->configs) as $configName) {
            foreach ([FileAccessor::ENTRYPOINTS, FileAccessor::MANIFEST] as $fileType) {
                $key = "$configName.$fileType";
                $this->cache->deleteItem($key);
                $this->logger->info('ViteBundle CacheRefreshController: deleted cache key "{key}"', [
                    'key' => $key,
                ]);
                $deleted[] = $key;
            }
        }

        $this->logger->info('ViteBundle CacheRefreshController: cache cleared, {count} key(s) deleted', [
            'count' => count($deleted),
        ]);

        return new JsonResponse(['message' => 'Vite manifest cache cleared.', 'keys' => $deleted], Response::HTTP_OK);
    }
}
