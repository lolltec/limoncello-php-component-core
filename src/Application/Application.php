<?php namespace Limoncello\Core\Application;

/**
 * Copyright 2015-2017 info@neomerx.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use Closure;
use Limoncello\Contracts\Container\ContainerInterface as LimoncelloContainerInterface;
use Limoncello\Contracts\Core\ApplicationInterface;
use Limoncello\Contracts\Core\SapiInterface;
use Limoncello\Contracts\Exceptions\ThrowableHandlerInterface;
use Limoncello\Contracts\Http\ThrowableResponseInterface;
use Limoncello\Contracts\Routing\RouterInterface;
use Limoncello\Contracts\Settings\SettingsProviderInterface;
use Limoncello\Core\Contracts\CoreSettingsInterface;
use Limoncello\Core\Reflection\CheckCallableTrait;
use Limoncello\Core\Routing\Router;
use LogicException;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Zend\Diactoros\Response\EmptyResponse;
use Zend\Diactoros\Response\TextResponse;
use Zend\Diactoros\ServerRequest;

/**
 * @package Limoncello\Core
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class Application implements ApplicationInterface
{
    use CheckCallableTrait;

    /** Method name for default request factory. */
    const FACTORY_METHOD = 'defaultRequestFactory';

    /** HTTP error code for default error response. */
    protected const DEFAULT_HTTP_ERROR_CODE = 500;

    /**
     * @var SapiInterface|null
     */
    private $sapi;

    /**
     * @var RouterInterface|null
     */
    private $router = null;

    /**
     * @return SettingsProviderInterface
     */
    abstract protected function createSettingsProvider(): SettingsProviderInterface;

    /**
     * @return LimoncelloContainerInterface
     */
    abstract protected function createContainerInstance(): LimoncelloContainerInterface;

    /**
     * @inheritdoc
     */
    public function setSapi(SapiInterface $sapi): ApplicationInterface
    {
        $this->sapi = $sapi;

        return $this;
    }

    /**
     * @inheritdoc
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function run(): void
    {
        if ($this->sapi === null) {
            throw new LogicException('SAPI not set.');
        }

        $container = null;

        try {
            $container = $this->createContainerInstance();

            $settingsProvider = $this->createSettingsProvider();
            $container->offsetSet(SettingsProviderInterface::class, $settingsProvider);

            $coreSettings = $settingsProvider->get(CoreSettingsInterface::class);

            // match route from `Request` to handler, route container configurators/middleware, etc
            list($matchCode, $allowedMethods, $handlerParams, $handler,
                $routeMiddleware, $routeConfigurators, $requestFactory) = $this->initRouter($coreSettings)
                ->match($this->sapi->getMethod(), $this->sapi->getUri()->getPath());

            // configure container
            $globalConfigurators = BaseCoreSettings::getGlobalConfiguratorsFromData($coreSettings);
            $this->configureContainer($container, $globalConfigurators, $routeConfigurators);

            // build pipeline for handling `Request`: global middleware -> route middleware -> handler (e.g. controller)

            // select terminal handler
            switch ($matchCode) {
                case RouterInterface::MATCH_FOUND:
                    $handler = $this->createTerminalHandler($handler, $handlerParams, $container);
                    break;
                case RouterInterface::MATCH_METHOD_NOT_ALLOWED:
                    $handler = $this->createMethodNotAllowedTerminalHandler($allowedMethods);
                    break;
                default:
                    assert($matchCode === RouterInterface::MATCH_NOT_FOUND);
                    $handler = $this->createNotFoundTerminalHandler();
                    break;
            }

            $globalMiddleware = BaseCoreSettings::getGlobalMiddlewareFromData($coreSettings);
            $hasMiddleware    = empty($globalMiddleware) === false || empty($routeMiddleware) === false;

            $handler = $hasMiddleware === true ?
                $this->addMiddlewareChain($handler, $container, $globalMiddleware, $routeMiddleware) : $handler;

            $request =
                $requestFactory === null && $hasMiddleware === false && $matchCode === RouterInterface::MATCH_FOUND ?
                null :
                $this->createRequest($this->sapi, $container, $requestFactory ?? static::getDefaultRequestFactory());

            // Execute the pipeline by sending `Request` down all middleware (global then route's then
            // terminal handler in `Controller` and back) and then send `Response` to SAPI
            $this->sapi->handleResponse($this->handleRequest($handler, $request));
        } catch (Throwable $throwable) {
            $this->sapi->handleResponse($this->handleThrowable($throwable, $container));
        }
    }

    /**
     * @return callable
     */
    public static function getDefaultRequestFactory(): callable
    {
        return [static::class, static::FACTORY_METHOD];
    }

    /**
     * @param SapiInterface $sapi
     *
     * @return ServerRequestInterface
     */
    public static function defaultRequestFactory(SapiInterface $sapi): ServerRequestInterface
    {
        return new ServerRequest(
            $sapi->getServer(),
            $sapi->getFiles(),
            $sapi->getUri(),
            $sapi->getMethod(),
            $sapi->getRequestBody(),
            $sapi->getHeaders(),
            $sapi->getCookies(),
            $sapi->getQueryParams(),
            $sapi->getParsedBody(),
            $sapi->getProtocolVersion()
        );
    }

    /**
     * @param Closure               $handler
     * @param RequestInterface|null $request
     *
     * @return ResponseInterface
     */
    protected function handleRequest(Closure $handler, RequestInterface $request = null): ResponseInterface
    {
        $response = call_user_func($handler, $request);

        assert($response instanceof ResponseInterface);

        return $response;
    }

    /**
     * @param Throwable                  $throwable
     * @param null|PsrContainerInterface $container
     *
     * @return ThrowableResponseInterface
     */
    protected function handleThrowable(
        Throwable $throwable,
        ?PsrContainerInterface $container
    ): ThrowableResponseInterface {
        if ($container !== null && $container->has(ThrowableHandlerInterface::class) === true) {
            /** @var ThrowableHandlerInterface $handler */
            $handler  = $container->get(ThrowableHandlerInterface::class);
            $response = $handler->createResponse($throwable, $container);
        } else {
            $response = $this->createDefaultThrowableResponse($throwable);
        }

        return $response;
    }

    /**
     * @param int   $status
     * @param array $headers
     *
     * @return ResponseInterface
     */
    protected function createEmptyResponse($status = 204, array $headers = []): ResponseInterface
    {
        $response = new EmptyResponse($status, $headers);

        return $response;
    }

    /**
     * @param Throwable $throwable
     *
     * @return ThrowableResponseInterface
     */
    protected function createDefaultThrowableResponse(Throwable $throwable): ThrowableResponseInterface
    {
        $status   = static::DEFAULT_HTTP_ERROR_CODE;
        $response = new class ($throwable, $status) extends TextResponse implements ThrowableResponseInterface
        {
            use ThrowableResponseTrait;

            /**
             * @param Throwable $throwable
             * @param int       $status
             */
            public function __construct(Throwable $throwable, int $status)
            {
                parent::__construct((string)$throwable, $status);
                $this->setThrowable($throwable);
            }
        };

        return $response;
    }

    /**
     * @return RouterInterface|null
     */
    protected function getRouter(): ?RouterInterface
    {
        return $this->router;
    }

    /**
     * @param LimoncelloContainerInterface $container
     * @param callable[]|null              $globalConfigurators
     * @param callable[]|null              $routeConfigurators
     *
     * @return void
     */
    protected function configureContainer(
        LimoncelloContainerInterface $container,
        array $globalConfigurators = null,
        array $routeConfigurators = null
    ): void {
        if (empty($globalConfigurators) === false) {
            foreach ($globalConfigurators as $configurator) {
                assert($this->checkPublicStaticCallable($configurator, [LimoncelloContainerInterface::class]));
                $configurator($container);
            }
        }
        if (empty($routeConfigurators) === false) {
            foreach ($routeConfigurators as $configurator) {
                assert($this->checkPublicStaticCallable($configurator, [LimoncelloContainerInterface::class]));
                $configurator($container);
            }
        }
    }

    /**
     * @param Closure               $handler
     * @param PsrContainerInterface $container
     * @param array|null            $globalMiddleware
     * @param array|null            $routeMiddleware
     *
     * @return Closure
     */
    protected function addMiddlewareChain(
        Closure $handler,
        PsrContainerInterface $container,
        array $globalMiddleware,
        array $routeMiddleware = null
    ): Closure {
        $handler = $this->createMiddlewareChainImpl($handler, $container, $routeMiddleware);
        $handler = $this->createMiddlewareChainImpl($handler, $container, $globalMiddleware);

        return $handler;
    }

    /**
     * @param callable                    $handler
     * @param array                       $handlerParams
     * @param PsrContainerInterface       $container
     * @param ServerRequestInterface|null $request
     *
     * @return ResponseInterface
     */
    protected function callHandler(
        callable $handler,
        array $handlerParams,
        PsrContainerInterface $container,
        ServerRequestInterface $request = null
    ): ResponseInterface {
        // check the handler method signature
        assert(
            $this->checkPublicStaticCallable(
                $handler,
                ['array', PsrContainerInterface::class, ServerRequestInterface::class],
                ResponseInterface::class
            ),
            'Handler method should have signature ' .
            '`public static methodName(array, PsrContainerInterface, ServerRequestInterface): ResponseInterface`'
        );

        $response = call_user_func($handler, $handlerParams, $container, $request);

        return $response;
    }

    /**
     * @param array $coreSettings
     *
     * @return RouterInterface
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    protected function initRouter(array $coreSettings): RouterInterface
    {
        $routerParams    = BaseCoreSettings::getRouterParametersFromData($coreSettings);
        $routesData      = BaseCoreSettings::getRoutesDataFromData($coreSettings);
        $generatorClass  = BaseCoreSettings::getGeneratorFromParametersData($routerParams);
        $dispatcherClass = BaseCoreSettings::getDispatcherFromParametersData($routerParams);

        $this->router = new Router($generatorClass, $dispatcherClass);
        $this->router->loadCachedRoutes($routesData);

        return $this->router;
    }

    /**
     * @param SapiInterface         $sapi
     * @param PsrContainerInterface $container
     * @param callable              $requestFactory
     *
     * @return ServerRequestInterface
     */
    private function createRequest(
        SapiInterface $sapi,
        PsrContainerInterface $container,
        callable $requestFactory
    ): ServerRequestInterface {
        // check the factory method signature
        assert(
            $this->checkPublicStaticCallable(
                $requestFactory,
                [SapiInterface::class, PsrContainerInterface::class],
                ServerRequestInterface::class
            ),
            'Factory method should have signature ' .
            '`public static methodName(SapiInterface, PsrContainerInterface): ServerRequestInterface`'
        );

        $request = call_user_func($requestFactory, $sapi, $container);

        return $request;
    }

    /**
     * @param callable              $handler
     * @param array                 $handlerParams
     * @param PsrContainerInterface $container
     *
     * @return Closure
     */
    private function createTerminalHandler(
        callable $handler,
        array $handlerParams,
        PsrContainerInterface $container
    ): Closure {
        return function (ServerRequestInterface $request = null) use (
            $handler,
            $handlerParams,
            $container
        ): ResponseInterface {
            try {
                return $this->callHandler($handler, $handlerParams, $container, $request);
            } catch (Throwable $throwable) {
                return $this->handleThrowable($throwable, $container);
            }
        };
    }

    /**
     * @param array $allowedMethods
     *
     * @return Closure
     */
    private function createMethodNotAllowedTerminalHandler(array $allowedMethods): Closure
    {
        // 405 Method Not Allowed
        return function () use ($allowedMethods): ResponseInterface {
            return $this->createEmptyResponse(405, ['Accept' => implode(',', $allowedMethods)]);
        };
    }

    /**
     * @return Closure
     */
    private function createNotFoundTerminalHandler(): Closure
    {
        // 404 Not Found
        return function (): ResponseInterface {
            return $this->createEmptyResponse(404);
        };
    }

    /**
     * @param Closure               $handler
     * @param PsrContainerInterface $container
     * @param array|null            $middleware
     *
     * @return Closure
     */
    private function createMiddlewareChainImpl(
        Closure $handler,
        PsrContainerInterface $container,
        array $middleware = null
    ): Closure {
        $start = count($middleware) - 1;
        for ($index = $start; $index >= 0; $index--) {
            $handler = $this->createMiddlewareChainLink($handler, $middleware[$index], $container);
        }

        return $handler;
    }

    /**
     * @param Closure               $next
     * @param callable              $middleware
     * @param PsrContainerInterface $container
     *
     * @return Closure
     */
    private function createMiddlewareChainLink(
        Closure $next,
        callable $middleware,
        PsrContainerInterface $container
    ): Closure {
        // check the middleware method signature
        assert(
            $this->checkPublicStaticCallable(
                $middleware,
                [ServerRequestInterface::class, Closure::class, PsrContainerInterface::class],
                ResponseInterface::class
            ),
            'Middleware method should have signature ' .
            '`public static methodName(ServerRequestInterface, Closure, PsrContainerInterface): ResponseInterface`'
        );

        return function (ServerRequestInterface $request) use ($next, $middleware, $container): ResponseInterface {
            return call_user_func($middleware, $request, $next, $container);
        };
    }
}
