<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace BroCode\Poppy\Controller;

use BroCode\Poppy\Controller\Api\Feedback;
use BroCode\Poppy\Controller\Api\Tasks;
use BroCode\Poppy\Controller\WellKnown\Poppy;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Maps /.well-known/poppy.json (a dot-prefixed path cannot be a standard frontName) and the Poppy API paths.
 */
class Router implements RouterInterface
{
    public const PATH = '/.well-known/poppy.json';
    public const TASKS_PATH = '/poppy/v1/tasks';
    public const FEEDBACK_PATH = '/poppy/v1/feedback';

    /**
     * @param ActionFactory $actionFactory
     */
    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    /**
     * Match the exact paths, nothing else.
     *
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        // FrontController::dispatch() answers a NotFoundException (e.g. a wrong HTTP method) with initForward()
        // and action 'noroute', and DefaultRouter forwards on to the CMS 404 page; each step matches again with the
        // same path. initForward() records the original route, so a request with forward info is never ours.
        if (!$request instanceof HttpRequest || !empty($request->getBeforeForwardInfo())) {
            return null;
        }

        return match ($request->getPathInfo()) {
            self::PATH => $this->dispatch($request, 'wellknown', 'poppy', Poppy::class),
            self::TASKS_PATH => $this->dispatch($request, 'api', 'tasks', Tasks::class),
            self::FEEDBACK_PATH => $this->dispatch($request, 'api', 'feedback', Feedback::class),
            default => null,
        };
    }

    /**
     * Set the route names on the request and create the action.
     *
     * @param HttpRequest $request
     * @param string $controller
     * @param string $action
     * @param class-string<ActionInterface> $class
     * @return ActionInterface
     */
    private function dispatch(HttpRequest $request, string $controller, string $action, string $class): ActionInterface
    {
        $request->setModuleName('brocode_poppy')->setControllerName($controller)->setActionName($action);

        return $this->actionFactory->create($class);
    }
}
