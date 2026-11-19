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

namespace BroCode\Poppy\Test\Unit\Controller;

use BroCode\Poppy\Controller\Api\Feedback;
use BroCode\Poppy\Controller\Api\Tasks;
use BroCode\Poppy\Controller\Router;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    /**
     * Router whose action factory returns a mock of the requested class.
     *
     * @return Router
     */
    private function router(): Router
    {
        $factory = $this->createMock(ActionFactory::class);
        $factory->method('create')->willReturnCallback(function (string $class): ActionInterface {
            /** @var class-string<ActionInterface> $class */
            return $this->createMock($class);
        });

        return new Router($factory);
    }

    /**
     * Request for the path. Fresh: no action, no forward info. Forwarded: as FrontController::dispatch() leaves it
     * after a NotFoundException (initForward() keeps the original route, action 'noroute'), or as DefaultRouter
     * leaves it one pass later (forwarded on to cms/noroute/index).
     *
     * @param string $path
     * @param string|null $actionName
     * @return HttpRequest
     */
    private function request(string $path, ?string $actionName = null): HttpRequest
    {
        $forwardInfo = $actionName === null ? [] : [
            'params' => [],
            'action_name' => 'tasks',
            'controller_name' => 'api',
            'module_name' => 'brocode_poppy',
            'route_name' => null,
        ];
        $request = $this->createMock(HttpRequest::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getActionName')->willReturn($actionName);
        $request->method('getBeforeForwardInfo')->willReturn($forwardInfo);
        $request->method('setModuleName')->willReturnSelf();
        $request->method('setControllerName')->willReturnSelf();
        $request->method('setActionName')->willReturnSelf();

        return $request;
    }

    public function testMatchesTasks(): void
    {
        $this->assertInstanceOf(Tasks::class, $this->router()->match($this->request('/poppy/v1/tasks')));
    }

    public function testMatchesFeedback(): void
    {
        $this->assertInstanceOf(Feedback::class, $this->router()->match($this->request('/poppy/v1/feedback')));
    }

    public function testIgnoresOtherPaths(): void
    {
        $this->assertNull($this->router()->match($this->request('/poppy/v1/other')));
    }

    public function testLeavesTasksToTheNotFoundHandlerAfterAMethodMismatch(): void
    {
        // FrontController::dispatch: NotFoundException -> initForward(), setActionName('noroute'), re-match.
        $this->assertNull($this->router()->match($this->request('/poppy/v1/tasks', 'noroute')));
    }

    public function testLeavesFeedbackToTheNotFoundHandlerAfterAMethodMismatch(): void
    {
        $this->assertNull($this->router()->match($this->request('/poppy/v1/feedback', 'noroute')));
    }

    public function testLeavesTheRequestToCmsNoRouteOnTheNextPass(): void
    {
        // Observed in the sandbox: DefaultRouter forwards to cms/noroute/index and the loop matches once more.
        $this->assertNull($this->router()->match($this->request('/poppy/v1/tasks', 'index')));
    }

    public function testLeavesPoppyJsonToTheNotFoundHandlerAfterAForward(): void
    {
        $this->assertNull($this->router()->match($this->request('/.well-known/poppy.json', 'noroute')));
    }
}
