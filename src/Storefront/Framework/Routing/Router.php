<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Routing;

use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Routing\Router as SymfonyRouter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

class Router implements RouterInterface, RequestMatcherInterface, WarmableInterface, ServiceSubscriberInterface
{
    /**
     * @var SymfonyRouter
     */
    private $decorated;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(SymfonyRouter $decorated, RequestStack $requestStack)
    {
        $this->decorated = $decorated;
        $this->requestStack = $requestStack;
    }

    public static function getSubscribedServices()
    {
        return SymfonyRouter::getSubscribedServices();
    }

    public function warmUp($cacheDir)
    {
        return $this->decorated->warmUp($cacheDir);
    }

    public function matchRequest(Request $request)
    {
        if (!$request->attributes->has(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID)) {
            return $this->decorated->matchRequest($request);
        }

        $server = array_merge(
            $_SERVER,
            ['REQUEST_URI' => $request->attributes->get(RequestTransformer::SALES_CHANNEL_RESOLVED_URI)]
        );

        $clone = $request->duplicate(null, null, null, null, null, $server);

        return $this->decorated->matchRequest($clone);
    }

    public function setContext(RequestContext $context)
    {
        return $this->decorated->setContext($context);
    }

    public function getContext()
    {
        return $this->decorated->getContext();
    }

    public function getRouteCollection()
    {
        return $this->decorated->getRouteCollection();
    }

    public function generate($name, $parameters = [], $referenceType = self::ABSOLUTE_PATH)
    {
        if (!$this->isStorefrontRoute($name)) {
            return $this->decorated->generate($name, $parameters, $referenceType);
        }

        $salesChannelBaseUrl = $this->getSalesChannelBaseUrl();
        $basePath = $this->getBasePath();

        // we need to insert the sales channel base url between the baseUrl and the infoPath
        switch ($referenceType) {
            case self::NETWORK_PATH:
            case self::ABSOLUTE_URL:

                $schema = '';
                if ($referenceType === self::ABSOLUTE_URL) {
                    $schema = $this->getContext()->getScheme() . ':';
                }
                $schemaAuthority = $schema . '//' . $this->getContext()->getHost();

                if ($this->getContext()->getHttpPort() !== 80) {
                    $schemaAuthority = $schemaAuthority . ':' . $this->getContext()->getHttpPort();
                } elseif ($this->getContext()->getHttpsPort() !== 443) {
                    $schemaAuthority = $schemaAuthority . ':' . $this->getContext()->getHttpsPort();
                }
                $generated = $this->decorated->generate($name, $parameters);
                $pathInfo = $this->removePrefix($generated, $basePath);

                $rewrite = $schemaAuthority . rtrim($salesChannelBaseUrl, '/') . $pathInfo;
                break;

            case self::RELATIVE_PATH:
                // remove base path from generated url (/shopware/public or /)
                $generated = $this->removePrefix(
                    $this->decorated->generate($name, $parameters, self::RELATIVE_PATH),
                    $basePath
                );

                // url contains the base path and the base url
                    // base url /shopware/public/de
                $rewrite = ltrim($salesChannelBaseUrl, '/') . $generated;
                break;

            case self::ABSOLUTE_PATH:
            default:
                $generated = $this->removePrefix(
                    $this->decorated->generate($name, $parameters),
                    $basePath
                );

                $rewrite = $basePath . rtrim($salesChannelBaseUrl, '/') . $generated;
                break;
        }

        return $rewrite;
    }

    public function match($pathinfo)
    {
        return $this->decorated->match($pathinfo);
    }

    private function removePrefix(string $subject, string $prefix): string
    {
        if (!$prefix || strpos($subject, $prefix) !== 0) {
            return $subject;
        }

        return substr($subject, strlen($prefix));
    }

    private function getSalesChannelBaseUrl(): string
    {
        $request = $this->requestStack->getMasterRequest();
        if (!$request) {
            return '';
        }

        $url = (string) $request->attributes->get(RequestTransformer::SALES_CHANNEL_BASE_URL);

        if (empty($url)) {
            return $url;
        }

        return '/' . trim($url, '/') . '/';
    }

    private function getBasePath(): string
    {
        $request = $this->requestStack->getMasterRequest();
        if (!$request) {
            return '';
        }

        return $request->getBasePath();
    }

    private function isStorefrontRoute(string $name): bool
    {
        return strncmp($name, 'frontend.', 9) === 0
            || strncmp($name, 'widgets.', 8) === 0
            || strncmp($name, 'payment.', 8) === 0;
    }
}
