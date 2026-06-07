<?php

namespace Nexph\Runtime\EventLoop;

use Nexph\Support\Extension\ExtensionDetector;

class EventLoopFactory
{
    public static function create(): EventLoopInterface
    {
        if (ExtensionDetector::has('event')) {
            return new EventEventLoop();
        }

        if (ExtensionDetector::has('ev')) {
            return new EvEventLoop();
        }

        if (ExtensionDetector::has('uv')) {
            return new UvEventLoop();
        }

        return new SelectEventLoop();
    }
}
